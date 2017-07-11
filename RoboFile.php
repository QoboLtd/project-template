<?php
/**
 * This is project's console commands configuration for Robo task runner.
 *
 * @see http://robo.li/
 */
class RoboFile extends \Robo\Tasks
{
    public $dotEnvDir = __DIR__;
    public $dotEnvFile = '.env';

    /**
     * Figure out the location of the .env file
     *
     * @return string
     */
    protected function getDotEnvPath()
    {
        $result = $this->dotEnvDir . DIRECTORY_SEPARATOR . $this->dotEnvFile;
        return $result;
    }

    /**
     * Parse command line arguments to .env
     *
     * Expects a string like "FOO=bar BLAH=true".  Returns an
     * associative array of ['FOO'=>'bar','BLAH'=>'true'].
     *
     * Doesn't like spaces and equals signs, unless used as
     * above.
     *
     * @param string $args Arguments to parse
     * @return array
     */
    protected function parseDotEnvArgs($args = null)
    {
        $result = [];

        $args = (string)$args;
        if (empty($args)) {
            return $result;
        }

        $args = explode(' ', $args);
        foreach ($args as $arg) {
            list($key, $value) = explode('=', $arg);
            $result[ $key ] = $value;
        }

        return $result;
    }


    /**
     * Load configuration from .env
     *
     * @param bool $force Force loading with mutability control
     * @return void
     */
    protected function loadDotEnv($force = false)
    {
        try {
            if ($force) {
                Dotenv::makeMutable();
            }

            Dotenv::load($this->dotEnvDir, $this->dotEnvFile);

            if ($force) {
                Dotenv::makeImmutable();
            }
        } catch (\Exception $e) {
            $this->yell("Failed to load .env configuration file");
        }
    }

    /**
     * Get project version
     *
     * Get project version from GIT_BRANCH environment variable,
     * or from the last git commit, or returns the default.
     *
     * @return string
     */
    protected function getProjectVersion()
    {
        $this->say("Getting project version");
        // Default value
        $result = 'Unknown';

        // Try to get version from the GIT_BRANCH environment variable
        $version = getenv('GIT_BRANCH');
        if (!empty($version)) {
            $result = $version;
            $this->say("Version: $result");
            return $result;
        }

        // Try to get version from the git commit hash
        $version = $this->taskGitStack()
            ->exec(['log', '-1 --pretty=format:"%h"'])
            ->run()
            ->getMessage();
        if (!empty($version)) {
            $result = $version;
            $this->say("Version: $result");
            return $result;
        }

        // Return default
        $this->say("Version: $result");

        return $result;
    }

    /**
     * Log project version to a given file
     *
     * @throws RuntimeException When cannot write version to file
     * @param string $file Path to where the version should be logged
     * @param string $version (Optional) version to log
     * @param bool $backup Whether or not to backup the file
     * @return void
     */
    protected function logProjectVersion($file, $version = null, $backup = true)
    {
        $this->say("Logging project version to [$file]");
        if (empty($version)) {
            $this->say("No project version specified");
            $version = $this->getProjectVersion();
        }

        if ($backup && file_exists($file)) {
            $this->say("Backing up [$file]");
            rename($file, $file . '.bak');
        }

        if (!file_put_contents($file, $version)) {
            throw new \RuntimeException("Failed to write version [$version] to file [$file]");
        }
    }

    /**
     * Install application
     *
     * Run all the steps necessary to install the application.
     * This is usually needed only once on every new environment.
     *
     * Pass the .env parameters like so:
     * robo app:install -- GIT_BRANCH=master DB_HOST=localhost
     *
     * @return void
     */
    public function appInstall($args = null)
    {
        $this->say("Installing application");

        // Pre
        $this->dotenvCreate($args);
        $this->dotenvReload($args);
        $this->logProjectVersion('build/version');

        // Install

        // Post
        $version = $this->logProjectVersion('build/version.ok');
    }

    /**
     * Update application
     *
     * Run all the steps necessary to update the application to
     * the given or latest version.
     *
     * Pass the .env parameters like so:
     * robo app:update -- GIT_BRANCH=master DB_HOST=localhost
     *
     * @return void
     */
    public function appUpdate($args = null)
    {
        $this->say("Updating application");

        // Pre
        $this->dotenvCreate($args);
        $this->dotenvReload($args);
        $this->logProjectVersion('build/version');

        // Update

        // Post
        $version = $this->logProjectVersion('build/version.ok');
    }

    /**
     * Remove application
     *
     * Run all the steps necessary to remove the application
     * from the current environment.
     *
     * Pass the .env parameters like so:
     * robo app:remove -- GIT_BRANCH=master DB_HOST=localhost
     *
     * @return void
     */
    public function appRemove($args = null)
    {
        $this->say("Removing application");

        // Pre
        $this->dotenvCreate($args);
        $this->dotenvReload($args);

        // Remove

        // Post
    }

    //
    // Move the below to a separate class
    //

    /**
     * Create .env file from .env.example and command lines arguments
     *
     * Create .env file from the .env.example file, with optional
     * overwrite of the settings.
     *
     * Pass the .env parameters like so:
     * robo dotenv:create -- GIT_BRANCH=master DB_HOST=localhost
     *
     * @return void
     */
    public function dotenvCreate($args = null)
    {
        $this->say("Creating .env file");

        $envPath = $this->getDotEnvPath();
        $envTemplatePath = $envPath . '.example';

        if (!file_exists($envTemplatePath)) {
            throw new \RuntimeException(".env template file [$envTemplatePath] does not exist");
        }
        if (!is_file($envTemplatePath)) {
            throw new \RuntimeException(".env template file [$envTemplatePath] is not a file");
        }
        if (!is_readable($envTemplatePath)) {
            throw new \RuntimeException(".env template file [$envTemplatePath] is not readable");
        }

        $args = $this->parseDotEnvArgs($args);

        $linesIn = file($envTemplatePath);
        $linesOut = [];

        $count = 0;
        $processedParams = [];
        foreach ($linesIn as $line) {
            $count++;
            $line = trim($line);
            if (!preg_match('#^(.*)?=(.*)?$#', $line, $matches)) {
                $linesOut[] = $line;
                continue;
            }
            $name = $matches[1];
            if (!in_array($name, $processedParams)) {
                $value = array_key_exists($name, $args) ? $args[$name] : $matches[2];
                $linesOut[] = $name . '=' . $value;
                $processedParams[] = $name;
            }
            // Remove current parameter from the all known parameters list
            if (in_array($name, $args)) {
                unset($args[$name]);
            }
        }
        // If anything left in known parameters, append it to the file
        foreach ($args as $name => $value) {
            if (!in_array($name, $processedParams)) {
                $linesOut[] = $name . '=' . $value;
            }
        }

        $bytes = file_put_contents($envPath, implode("\n", $linesOut));
        if (!$bytes) {
            throw new \RuntimeException("Failed to save $count lines to [$envPath]");
        }
        $this->say("Saved $count lines to $envPath");
    }

    /**
     * Reload .env file
     */
    public function dotenvReload()
    {
        $this->say("Reloading .env file");
        $this->loadDotEnv(true);
    }

    /**
     * Delete .env file
     *
     * @throws RuntimeException When couldn't delete file
     */
    public function dotenvDelete()
    {
        $this->say("Deleting .env file");

        $dotenvPath = $this->getDotEnvPath();
        if (!file_exists($dotenvPath)) {
            $this->yell("Failed to delete .env file. File not found [$dotenvPath].");
        }
        else {
            $result = unlink($dotenvPath);
            if ($result) {
                $this->say("Deleted .env file at [$dotenvPath]");
            }
            else {
                throw new \RuntimeException("Failed to delete .env file at [$dotenvPath]");
            }
        }
    }
}
