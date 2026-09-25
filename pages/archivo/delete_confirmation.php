<?php
#########################################################
# Custom page settings                                         #
#########################################################
$pageSettings['title'] = __('Custom html page', 'sticpa'); // List title
#########################################################
$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

$html .= "<div class='stic-entry-header'>
<h3>{$listSettings['title']}</h3>";
?>
<p>
    <span id='successMsg' class='success'><?=  __('Record successfully deleted.', 'sticpa')?></span>
</p>



