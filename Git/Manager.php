<?php
/**
 * Created by PhpStorm.
 * User: andrew
 * Date: 09.04.14
 * Time: 9:33
 * Author: Kluev Andrew
 * Contact: Kluev.Andrew@gmail.com
 */
namespace KA\SonataAdminJMSTranslationBundle\Git;

use Symfony\Component\Security\Core\SecurityContextInterface;
use FOS\UserBundle\Model\UserInterface as FOSUserInterface;

/**
 * Class KA\SonataAdminJMSTranslationBundle\Git\Manager
 */
class Manager
{
    /**
     * @var array
     */
    protected $cache = [];

    /**
     * @var \Symfony\Component\Security\Core\SecurityContextInterface
     */
    protected $securityContext;

    /**
     * @param SecurityContextInterface $securityContext
     */
    public function __construct(SecurityContextInterface $securityContext)
    {
        $this->securityContext = $securityContext;
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        if (function_exists('exec')) {
            exec('git --version', $gitVersion, $returnVar);
            if ($returnVar === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string
     */
    public function gitVersion()
    {
        exec('git --version', $gitVersion);

        return $gitVersion[0];
    }

    /**
     * @param string $directory
     *
     * @return bool
     */
    public function gitInitialized($directory)
    {
        $this->execInDir($directory, 'cat .git/config', $cat, $returnVar);

        return $returnVar === 0;
    }

    /**
     * @param string $directory
     *
     * @return bool
     */
    public function init($directory)
    {
        $this->execInDir($directory, 'git init', $gitInit, $returnVar);
        if ($returnVar == 0) {
            $this->execInDir($directory, ' git add -A && git commit -m "Initial commit"', $gitCommit, $returnVar);
            if ($returnVar == 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $directory
     * @param string $message
     *
     * @return bool
     */
    public function commit($directory, $message)
    {
        $command = sprintf('git add -A && git commit -m "%s"', $message);

        $token = $this->securityContext->getToken();
        if ($token && $token->getUser()) {
            $user        = $token->getUser();
            $authorName  = (string) $user;
            $authorEmail = 'unknown@e.mail';
            if (interface_exists('FOS\UserBundle\Model\UserInterface') and $user instanceof FOSUserInterface) {
                $authorEmail = $user->getEmail();
            }

            $command .= sprintf(' --author="%s <%s>"', $authorName, $authorEmail);
        }

        $this->execInDir($directory, $command, $gitCommit, $returnVar);

        if ($returnVar == 0) {
            return true;
        }

        return false;
    }

    /**
     * @param string $directory
     * @param array  $options
     * @param bool   $returnAsArray
     *
     * @return string|array
     */
    public function status($directory, array $options = [], $returnAsArray = true)
    {
        $this->execInDir($directory, sprintf('git status %s', implode(' ', $options)), $status, $returnVar);

        return $returnAsArray ? $status : implode(PHP_EOL, $status);
    }

    /**
     * @param string $directory
     * @param string $branch
     * @param array  $options
     * @param bool   $returnAsArray
     * @param int    &$returnVar
     *
     * @return string|array
     */
    public function branch($directory, $branch = '', array $options = [], $returnAsArray = true, &$returnVar = 0)
    {
        $command = sprintf('git branch %s %s', $branch, implode(' ', $options));
        $this->execInDir($directory, $command, $result, $returnVar);

        return $returnAsArray ? $result : implode(PHP_EOL, $result);
    }

    /**
     * @param string $directory
     * @param bool   $returnAsArray
     *
     * @return array|string
     */
    public function branchList($directory, $returnAsArray = true)
    {
        $result = $this->branch($directory, '', ['--list'], true);

        foreach ($result as &$branchRow) {
            $branchRow = trim(str_replace('*', '', $branchRow));
        }

        return $returnAsArray ? $result : implode(PHP_EOL, $result);
    }

    /**
     * @param string $directory
     * @param string $to
     * @param array  $options
     *
     * @return bool
     */
    public function reset($directory, $to, array $options = [])
    {
        $this->execInDir($directory, sprintf('git reset %s %s', $to, implode(' ', $options)), $result, $returnVar);

        $this->resetCache();

        return $returnVar === 0;
    }

    /**
     * @param string $directory
     * @param string $branch
     * @param array  $options
     *
     * @return bool
     */
    public function checkout($directory, $branch, array $options = [])
    {
        $this->execInDir(
            $directory,
            sprintf('git checkout %s %s', $branch, implode(' ', $options)),
            $result,
            $returnVar
        );

        $this->resetCache();

        return $returnVar === 0;
    }

    /**
     * @param string $directory
     *
     * @return string
     */
    public function branchCurrent($directory)
    {
        $cacheKey = __METHOD__ . $directory;
        if (!$this->hasCache($cacheKey)) {
            $result        = $this->branch($directory, '', ['--list'], true);
            $currentBranch = '';
            foreach ($result as &$branchRow) {
                $branchRow = trim(str_replace('*', '', $branchRow, $count));
                if ($count) {
                    $currentBranch = $branchRow;
                    break;
                }
            }
            $this->setCache($cacheKey, $currentBranch);
        }

        return $this->getCache($cacheKey);
    }

    /**
     * @param string $directory
     *
     * @return array
     * @throws \Exception
     */
    public function history($directory)
    {
        $this->execInDir($directory, 'git log --pretty=oneline', $output, $returnVal);

        $history = [];
        if ($returnVal === 0) {
            foreach ($output as $line) {
                preg_match('/^(.*?)\s(.*?)$/usi', $line, $matches);
                if (count($matches) === 3) {
                    $hash           = $matches[1];
                    $message        = $matches[2];
                    $history[$hash] = $message;
                }
            }

            return $history;
        }

        throw new \Exception();
    }

    /**
     * @param string $directory
     * @param string $revision1
     * @param string $revision2
     * @param array  $options
     * @param bool   $returnAsArray
     *
     * @return string|array
     * @throws \Exception
     */
    public function diff($directory, $revision1, $revision2, array $options = [], $returnAsArray = true)
    {
        if (empty($options)) {
            $options = [
                '--minimal',
                '--name-only'
            ];
        }

        $command = sprintf('git diff %s %s %s', implode(' ', $options), $revision1, $revision2);
        $this->execInDir($directory, $command, $output, $returnVal);

        if ($returnVal === 0) {
            return $returnAsArray ? $output : implode(PHP_EOL, $output);
        }

        return $returnAsArray ? [] : '';
    }

    /**
     * @param string $directory
     * @param string $revision1
     * @param string $revision2
     * @param array  $options
     *
     * @return bool
     * @throws \Exception
     */
    public function merge($directory, $revision1, $revision2, array $options = [])
    {
        $options[] = sprintf('-m "Merge %s into %s"', $revision2, $revision1);
        $command   = sprintf('git merge %s %s %s', implode(' ', $options), $revision1, $revision2);
        $this->execInDir($directory, $command, $output, $returnVal);

        return $returnVal === 0;
    }

    /**
     * @param string $directory
     * @param string $address
     * @param string $password
     * @param array  $options
     *
     * @return bool
     */
    public function push($directory, $address, $password, array $options = [])
    {
        $command   = sprintf('git push %s %s %s', implode(' ', $options), $address, $this->branchCurrent($directory));

        $descriptorSpec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $directory);
        if (is_resource($process)) {

            fwrite($pipes[0], $password);

            $stdin = stream_get_contents($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            var_dump($password,$stdin, $stdout, $stderr);

            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            $status = trim(proc_close($process));
            if ($status) {
                throw new \Exception($stderr);
            }

            return $stdout;
        }

        throw new \Exception();
    }

    /**
     * @param string $directory
     * @param string $command
     * @param array  &$output
     * @param int    &$returnVar
     */
    protected function execInDir($directory, $command, &$output, &$returnVar)
    {
        $command = sprintf('%s && %s', $this->cd($directory), $command);

        exec($command, $output, $returnVar);
    }

    /**
     * @param string $directory
     *
     * @return string
     */
    protected function cd($directory)
    {
        return sprintf('cd %s', $directory);
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    protected function setCache($key, $value)
    {
        $this->cache[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    protected function getCache($key)
    {
        return $this->cache[$key];
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function hasCache($key)
    {
        return isset($this->cache[$key]);
    }

    /**
     * @return $this
     */
    protected function resetCache()
    {
        $this->cache = [];

        return $this;
    }
}
 