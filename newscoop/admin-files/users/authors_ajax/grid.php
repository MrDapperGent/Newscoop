<?php
ini_set("display_errors","2");
error_reporting(E_ALL);
function l_getType($types)
{
    if (!is_array($types)) return;
    $t = "";
    foreach ($types as $type){
        if (strlen($t)>0) $t .= ", ";
        $t .= $type['type'];
    }
    return $t;
}
?>
<table style="width: 100%; margin: 0pt;" id="gridx" cellpadding="0" cellspacing="0" class="datatable">
<thead>
<tr>
  <th><?php putGS('Author'); ?></th>
  <th><?php putGS('Type'); ?></th>
  <th><?php putGS('Delete'); ?></th>
</tr>
</thead>
<tbody>
<?php
$authors = Author::GetAuthors();
$i = 0;
foreach($authors as $author) {
?>
<tr onclick="getRow(<?php echo $author->getId()?>)"
  onmouseover="setPointer(this, 0, 'over');" onmouseout="setPointer(this, 0, 'out');" style="cursor:pointer">
  <td><?php echo $author->getName(); ?></td>
  <td><?php echo l_getType($author->getTypeWithNames()); ?></td>
  <td align="right" class="last" id="row_0">
    <a href="?del_id=<?php echo $author->getId() ?>" onclick="return deleteAuthor();"><img
      src="<?php echo $Campsite["ADMIN_IMAGE_BASE_URL"]; ?>/delete.png" border="0"
      alt="<?php echo getGS('Delete author'); ?>" title="<?php echo getGS('Delete author'); ?>" /></a>
  </td>
</tr>
<?php
  $i++;
}?>
</tbody>
</table>
<script type="text/javascript">
$(function() {
    $('#gridx tr').click(function() {
        $('#gridx tr').removeClass('selected');
        $(this).addClass('selected');
    });
});
</script>