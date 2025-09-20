<?php

$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

?>
<h4><?=  __('Payment error', 'sticpa')?></h4>
<p><?=  __('There was an error in the payment. Please, try it again or contact the administrator.', 'sticpa')?></p>


