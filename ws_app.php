<?php

# this file is included by a webserver script to write to the named pipe, which will then be read by the websocket server
# this script is not included by the websocket server itself

#####################################################################################################

function ws_pipe_data(&$params)
{
  if (file_exists(WEBSOCKET_XHR_PIPE_FILE)==False)
  {
    $params["pipe_status"]="PIPE FILE NOT FOUND";
  }
  else
  {
    $xhr_pipe=fopen(WEBSOCKET_XHR_PIPE_FILE,"w+");
    if ($xhr_pipe===False)
    {
      $params["pipe_status"]="ERROR OPENING PIPE FILE";
    }
    else
    {
      if (isset($params["wsmanage"]["cmd"])==True)
      {
        fwrite($xhr_pipe,$params["wsmanage"]["cmd"]);
      }
      else
      {
        fwrite($xhr_pipe,json_encode($params));
      }
      fclose($xhr_pipe);
      $params["pipe_status"]="OK";
    }
  }
}

#####################################################################################################
