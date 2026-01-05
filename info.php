<?php

require_once 'includes/common.inc.php';
global $redis, $config, $csrfToken, $server;

if (isset($_GET['reset'])) {
  // CONFIG command is not supported in cluster mode
  if (!isset($server['cluster']) || !$server['cluster']) {
    try {
      $redis->config('resetstat');
    } catch (Exception $e) {
      // Ignore error
    }
  }

  header('Location: info.php');
  die;
}

// Fetch the info
// In cluster mode, INFO command needs special handling
if (isset($server['cluster']) && $server['cluster']) {
  try {
    $info = $redis->executeRaw(['INFO']);
    // Parse INFO response if it's a string
    if (is_string($info)) {
      $parsedInfo = array();
      $lines = explode("\r\n", $info);
      foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || $line[0] === '#') continue;
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
          $parsedInfo[$parts[0]] = $parts[1];
        }
      }
      $info = $parsedInfo;
    }
  } catch (Exception $e) {
    $info = array('error' => 'Cluster mode: INFO command limited');
  }
} else {
  try {
    $info = $redis->info();
  } catch (Exception $e) {
    $info = array('error' => $e->getMessage());
  }
}
$alt  = false;

$page['css'][] = 'frame';
$page['js'][]  = 'frame';

require 'includes/header.inc.php';

?>
<h2>Info</h2>

<p>
<a href="?reset=1&amp;s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>" class="reset">Reset usage statistics</a>
</p>

<table>
<tr><th><div>Key</div></th><th><div>Value</div></th></tr>
<?php

foreach ($info as $key => $value) {
  if ($key == 'allocation_stats') { // This key is very long to split it into multiple lines
    $value = str_replace(',', ",\n", $value);
  }

  ?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div><?php echo format_html($key)?></div></td><td><pre><?php echo format_html(is_array($value) ? print_r($value, true) : $value)?></pre></td></tr>
  <?php

  $alt = !$alt;
}

?>
</table>
<?php

require 'includes/footer.inc.php';

?>
