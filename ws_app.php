<?php

# this file is included by a webserver ajax stub handler script to write to the named pipe, which will then be read by the websocket server
# this script is not included by the websocket server itself

#####################################################################################################

function ws_pipe_data(&$params)
{
  define("WS_PIPE_FILE","/var/include/vhosts/default/inc/data/notify_iface");
  if (file_exists(WS_PIPE_FILE)==False)
  {
    $params["pipe_status"]="PIPE FILE NOT FOUND";
  }
  else
  {
    $pipe=fopen(WS_PIPE_FILE,"a");
    if ($pipe===False)
    {
      $params["pipe_status"]="ERROR OPENING PIPE FILE";
    }
    else
    {
      fwrite($pipe,json_encode($params));
      fclose($pipe);
      $params["pipe_status"]="OK";
    }
  }
}

#####################################################################################################

?>
