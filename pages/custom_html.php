<?php
#########################################################
# Custom page settings                                         #
#########################################################
$pageSettings['title'] = __('Custom html page', 'sticpa'); // List title
#########################################################
$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

$html .= "<div class='stic-entry-header'>";
?>
<h4><?=  __('Lorem Ipsum', 'sticpa')?></h4>
<p><?=  __('"Neque porro quisquam est qui dolorem ipsum quia dolor sit amet, consectetur, adipisci velit..."', 'sticpa')?></p>


<table>
    <tr>
        <td>
            <img src="<?php echo plugins_url('../images/sinergiacrm.jpg', __FILE__);?>" >
        </td>
        <td>

            <form action="">
            <iframe width="300" height="200" src="https://www.youtube.com/embed/9n8YRA-IMDo" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
            </form>

        </td>
    </tr>
</table>
