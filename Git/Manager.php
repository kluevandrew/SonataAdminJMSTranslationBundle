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

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\SecurityContextInterface;

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
        try {
            $this->version();

            return true;
        } catch (ProcessFailedException $e) {
        }

        return false;
    }

    /**
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    public function version()
    {
        return $this->exec('git --version');
    }

    /**
     * @param string $directory
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    public function gitInitialized($directory)
    {
        try {
            return $this->exec('cat .git/config', $directory);
        } catch (ProcessFailedException $e) {
        }

        return false;
    }

    /**
     * @param string $directory
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    public function init($directory)
    {
        $this->exec('git init', $directory);

        return $this->commit($directory, 'Initial commit');
    }

    /**
     * @param string $directory
     * @param string $message
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     * @throws \InvalidArgumentException
     */
    public function commit($directory, $message)
    {
        if (!$message) {
            throw new \InvalidArgumentException('Message can\'t be empty');
        }

        $this->exec('git add -A', $directory);

        $command = sprintf('git add -A && git commit -m "%s"', $message);
        $token   = $this->securityContext->getToken();
        if ($token && $token->getUser()) {
            $user        = $token->getUser();
            $authorName  = (string) $user;
            $authorEmail = 'unknown@e.mail';
            if (interface_exists('\FOS\UserBundle\Model\UserInterface')
                and
                $user instanceof \FOS\UserBundle\Model\UserInterface
            ) {
                $authorEmail = $user->getEmail();
            }

            $command .= sprintf(' --author="%s <%s>"', $authorName, $authorEmail);
        }

        return $this->exec($command, $directory);
    }

    /**
     * @param string $directory
     * @param array  $options
     * @param bool   $returnAsArray
     *
     * @return array|string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    public function status($directory, array $options = [], $returnAsArray = true)
    {
        $command = sprintf('git status %s', implode(' ', $options));
        $result  = $this->exec($command, $directory, $array);

        return $returnAsArray ? $array : $result;
    }

    /**
     * @param string $directory
     * @param string $branch
     * @param array  $options
     * @param bool   $returnAsArray
     *
     * @return array|string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     * @throws \InvalidArgumentException
     */
    public function branch($directory, $branch = '', array $options = [], $returnAsArray = true)
    {
        if (preg_match('/[^a-z_\-0-9]/usi', $branch)) {
            throw new \InvalidArgumentException('Bad branch name');
        }

        $command = sprintf('git branch %s %s', $branch, implode(' ', $options));

        $result = $this->exec($command, $directory, $array);

        return $returnAsArray ? $array : $result;
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
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     * @throws \InvalidArgumentException
     */
    public function reset($directory, $to, array $options = [])
    {
        if (!$to) {
            throw new \InvalidArgumentException('Revision can\'t be empty');
        }

        $command = sprintf('git reset %s %s', $to, implode(' ', $options));

        $this->resetCache();

        return $this->exec($command, $directory);
    }

    /**
     * @param string $directory
     * @param string $branch
     * @param array  $options
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     * @throws \InvalidArgumentException
     */
    public function checkout($directory, $branch, array $options = [])
    {
        if (!$branch) {
            throw new \InvalidArgumentException('Branch can\'t be empty');
        }

        $command = sprintf('git checkout %s %s', $branch, implode(' ', $options));

        $this->resetCache();

        return $this->exec($command, $directory);
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
            if ($result) {
                foreach ($result as &$branchRow) {
                    $branchRow = trim(str_replace('*', '', $branchRow, $count));
                    if ($count) {
                        $currentBranch = $branchRow;
                        break;
                    }
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
        $result = $this->exec('git log --pretty=oneline', $directory);

        $lines = explode(PHP_EOL, $result);

        $history = [];

        foreach ($lines as $line) {
            preg_match('/^(.*?)\s(.*?)$/usi', $line, $matches);
            if (count($matches) === 3) {
                $hash           = $matches[1];
                $message        = $matches[2];
                $history[$hash] = $message;
            }
        }

        return $history;
    }

    /**
     * @param string $directory
     * @param string $revision1
     * @param string $revision2
     * @param array  $options
     * @param bool   $returnAsArray
     *
     * @return array|string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    public function diff($directory, $revision1, $revision2, array $options = [], $returnAsArray = true)
    {
        if (empty($options)) {
            $options = [
                '--minimal',
                '--name-only'
            ];
        }

        $command = sprintf('git log %s %s..%s', implode(' ', $options), $revision1, $revision2);

        $result = $this->exec($command, $directory, $array);

        return $returnAsArray ? $array : $result;
    }

    /**
     * @param string $directory
     * @param string $revision1
     * @param string $revision2
     * @param array  $options
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     * @throws \InvalidArgumentException
     */
    public function merge($directory, $revision1, $revision2, array $options = [])
    {
        if (!$revision1 || !$revision2) {
            throw new \InvalidArgumentException('Revisions can\'t be empty');
        }
        if ($revision1 === $revision2) {
            throw new \InvalidArgumentException('Can\'t merge same revisions');
        }
        if (!$this->diff($directory, $revision1, $revision2)) {
            throw new \InvalidArgumentException('Already merged');
        }

        $options[] = sprintf('-m "Merge %s into %s"', $revision2, $revision1);
        $command   = sprintf('git merge %s %s %s', implode(' ', $options), $revision1, $revision2);

        return $this->exec($command, $directory);
    }

    /**
     * @param string $command
     * @param string $directory
     * @param array  &$arrayResult
     *
     * @return string
     * @throws \Symfony\Component\Process\Exception\LogicException
     * @throws \Symfony\Component\Process\Exception\ProcessFailedException
     */
    protected function exec($command, $directory = null, &$arrayResult = [])
    {
        $process = new Process($command, $directory);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $result = trim($process->getOutput());

        $result ? $arrayResult = explode(PHP_EOL, $result) : [];

        return $result;
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
 