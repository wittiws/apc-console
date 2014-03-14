<?php
namespace ApcConsole;

use Symfony\Component\Yaml\Yaml;
class ApcConsole {
  /**
   * Construct the ApcConsole with a path to configurations or with an array.
   *
   * WARNING: If using the array option, you must be doubly certain that the script
   *   is not web-accessible
   *
   * @todo Add another level of security so that web-accessible versions are safe.
   * @param string $conf_path
   * @throws \InvalidArgumentException
   * @throws \ErrorException
   * @return \ApcConsole\ApcConsole
   */
  static public function factory($conf_path = NULL) {
    // Set the default.
    $default_conf_path = dirname(__DIR__) . '/settings.php';
    if (!isset($conf)) {
      $conf_path = $default_conf_path;
    }

    // Parse the configuration file.
    if (is_string($conf_path)) {
      if (!file_exists($conf_path)) {
        throw new \InvalidArgumentException("Invalid path to the configuration file.");
      }

      // Parse the configuration file.
      $data = include($conf_path);
      if (!is_array($data)) {
        throw new \ErrorException("ApcConsole requires the settings files to return an array.");
      }
      if ($conf_path === $default_conf_path) {
        $data['conf_path'] = NULL;
      }
      else {
        $data['conf_path'] = $conf_path;
      }

      return new ApcConsole($data);
    }

    // Load the configuration from an array.
    // Note that this impacts how the web portion needs to be configured.
    if (is_array($conf_path)) {
      $conf['conf_path'] = FALSE;
      return new ApcConsole($conf_path);
    }

    throw new \InvalidArgumentException("ApcConsole requires a configuration passed via array or path to YAML file.");
  }

  protected $settings = array();
  public function __construct($conf = NULL) {
    $this->settings = array_replace_recursive(array(
      'url' => NULL,
      'usercache_dump' => '/run/shm/apc-usercache.dump',
      'usercache_max_age' => 600,
      'usercache_min_size' => 0,
      'secret' => uniqid(),
      'conf_path' => NULL,
    ), (array) $conf);
    if ($this->settings['conf_path'] === FALSE) {
      $this->settings['secret'] = NULL;
    }
  }

  public function auto() {
    if (!isset($_SERVER['REQUEST_METHOD'])) {
      try {
        $this->consoleLoadUserCache();
      } catch (\Exception $e) {
        return $e->getMessage() . "\n";
      }
      return;
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
      try {
        // If there is a secret, then use it.
        if (isset($this->settings['secret'])) {
          if (!isset($_GET['secret']) || $_GET['secret'] !== $this->settings['secret']) {
            throw new \ErrorException("A valid secret is required.");
          }
        }

        // Select the function.
        $func = 'web' . (isset($_GET['cmd']) ? $_GET['cmd'] : ' invalid ');
        if (!method_exists($this, $func)) {
          throw new \ErrorException("A valid cmd is required.");
        }

        // Execute the function.
        $args = isset($_GET['args']) ? $_GET['args'] : array();
        $this->$func($args);

        return "OK";
      } catch (\Exception $e) {
        return $e->getMessage();
      }
    }
  }

  protected function executeConsole($cmd, $args = NULL) {
    // Get the URL setting.
    $url = $this->settings['url'];
    if (!isset($url)) {
      throw new \ErrorException("You must provide an URL");
    }

    // Build the query string.
    $qs = array(
      'cmd' => $cmd,
    );
    if (isset($this->settings['conf_path'])) {
      if ($this->settings['conf_path'] === FALSE) {
        $qs['conf'] = array_intersect_key($this->settings, array_flip(array(
          'usercache_dump',
        )));
      }
      $qs['conf_path'] = $this->settings['conf_path'];
    }
    if (isset($this->settings['secret'])) {
      $qs['secret'] = $this->settings['secret'];
    }

    // Finalize the URL.
    $url .= '?' . http_build_query($qs);

    // Call the URL.
    $response = file_get_contents($url);
    return $response;
  }

  public function isUserCacheStale() {
    $path = $this->settings['usercache_dump'];
    if (!file_exists($path)) {
      return TRUE;
    }
    $age = time() - filemtime($path);
    if ($age >= $this->settings['usercache_max_age']) {
      return TRUE;
    }
    if ($this->settings['usercache_min_size'] && filesize($path) < $this->settings['usercache_min_size']) {
      return TRUE;
    }
    return FALSE;
  }

  public function consoleLoadUserCache() {
    if ($this->isUserCacheStale()) {
      $this->executeConsole('saveusercache');
    }
    $dumpfile_path = $this->settings['usercache_dump'];
    if (file_exists($dumpfile_path)) {
      $ret = apc_bin_loadfile($dumpfile_path);
      return TRUE;
    }
    throw new \ErrorException("Unable to load the usercache dump.");
  }

  public function webSaveUserCache() {
    if ($this->isUserCacheStale()) {
      $path = $this->settings['usercache_dump'];
      $lock = $path . '.lock';
      $tmp = $path . '.' . uniqid();
      if (file_exists($lock)) {
        $limit = 5;
        $usleep = 10000;
        while ($limit > 0) {
          $limit -= $usleep / 1000000;
          usleep($usleep);
          if (!file_exists($lock)) {
            return TRUE;
          }
        }
      }

      touch($lock);
      apc_bin_dumpfile(array(), NULL, $tmp, LOCK_EX);
      if (file_exists($tmp)) {
        if (filesize($tmp)) {
          rename($tmp, $path);
        }
        else {
          unlink($tmp);
        }
      }
      unlink($lock);
    }
    return TRUE;
  }

}