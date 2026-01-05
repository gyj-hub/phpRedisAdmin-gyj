<?php

require_once 'includes/common.inc.php';
global $redis, $config, $csrfToken, $server;

$page['css'][] = 'frame';
$page['js'][]  = 'frame';

require 'includes/header.inc.php';

?>
<h2>Saving</h2>

...
<?php

// Flush everything so far cause the next command could take some time.
flush();

// SAVE command handling for cluster mode
if (isset($server['cluster']) && $server['cluster']) {
  // In cluster mode, SAVE is not supported
  // We can try BGSAVE on each node, but this is risky
  echo "<br><span style='color: orange;'>Warning: SAVE command is not supported in cluster mode.</span>";
  echo "<br>Cluster mode uses automatic persistence. Manual save is not available.";
} else {
  try {
    $redis->save();
    echo " done.";
  } catch (Exception $e) {
    echo "<br><span style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</span>";
  }
}

?>
<?php

require 'includes/footer.inc.php';

?>