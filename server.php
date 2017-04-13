<?php

#####################################################################################################

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("UTC");
ini_set("memory_limit","512M");

register_shutdown_function("shutdown_handler");
pcntl_signal(SIGTERM,"signal_handler"); # required for shutdown handler to be called on systemctl stop
$shutdown_flag=True;

set_error_handler("error_handler");

require_once("shared_utils.php");

define("SETTINGS_FILENAME","/var/include/vhosts/default/inc/data/server_shared.conf");
if (file_exists(SETTINGS_FILENAME)==False)
{
  show_message("ERROR: SETTINGS FILE NOT FOUND",True);
  return;
}
$settings=file_get_contents(SETTINGS_FILENAME);
$settings=explode(PHP_EOL,$settings);
for ($i=0;$i<count($settings);$i++)
{
  $line=trim($settings[$i]);
  if ($line=="")
  {
    continue;
  }
  $keyval=explode("=",$line);
  $key=array_shift($keyval);
  $val=implode("=",$keyval);
  define($key,$val);
}

if (file_exists(WEBSOCKET_EVENTS_INCLUDE_FILE)==False)
{
  show_message("ERROR: EVENTS INCLUDE FILE NOT FOUND",True);
  return;
}
require_once(WEBSOCKET_EVENTS_INCLUDE_FILE); # contains functions to handle events for the specific application

if (function_exists("ws_server_initialize")==True)
{
  ws_server_initialize();
}

umask(0);
if (file_exists(WEBSOCKET_XHR_PIPE_FILE)==True)
{
  unlink(WEBSOCKET_XHR_PIPE_FILE);
}
if (posix_mkfifo(WEBSOCKET_XHR_PIPE_FILE,0666)==False)
{
  show_message("ERROR: UNABLE TO MAKE FIFO NAMED PIPE FILE",True);
  return;
}
$xhr_pipe=fopen(WEBSOCKET_XHR_PIPE_FILE,"r+");
stream_set_blocking($xhr_pipe,0);

$sockets=array();
$connections=array();
$server=stream_socket_server("tcp://".WEBSOCKET_LISTENING_ADDRESS.":".WEBSOCKET_LISTENING_PORT,$err_no,$err_msg);
if ($server===False)
{
  show_message("could not bind to socket: ".$err_msg,True);
  return;
}
show_message("<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< PUSH SERVER STARTED >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");
stream_set_blocking($server,0);
$sockets[]=$server;
if (function_exists("ws_server_started")==True)
{
  ws_server_started($server,$sockets,$connections);
}
while (True)
{
  $read=array($xhr_pipe);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,WEBSOCKET_SELECT_TIMEOUT);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data="";
      do
      {
        $buffer=fread($xhr_pipe,1024);
        if ($buffer===False)
        {
          show_message("xhr pipe read error",True);
          break;
        }
        $data.=$buffer;
      }
      while (strlen($buffer)>0);
      $data=trim($data);
      if (function_exists("ws_server_fifo")==True)
      {
        ws_server_fifo($server,$sockets,$connections,$data);
      }
    }
  }
  $read=array(STDIN);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets(STDIN));
      if ($data=="q")
      {
        $shutdown_flag=True;
        foreach ($sockets as $key => $socket)
        {
          if ($sockets[$key]!==$server)
          {
            close_client($key,1001,"server shutting down");
          }
        }
        break;
      }
    }
  }
  if (function_exists("ws_server_loop")==True)
  {
    ws_server_loop($server,$sockets,$connections);
  }
  $read=$sockets;
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0);
  if ($change_count===False)
  {
    show_message("stream_select on sockets failed",True);
    break;
  }
  if ($change_count>=1)
  {
    foreach ($read as $read_key => $read_socket)
    {
      if ($read[$read_key]===$server)
      {
        $client=stream_socket_accept($server,120);
        if (($client===False) or ($client==Null))
        {
          show_message("stream_socket_accept error/timeout",True);
          continue;
        }
        stream_set_blocking($client,0);
        $sockets[]=$client;
        $client_key=array_search($client,$sockets,True);
        $new_connection=array();
        $new_connection["peer_name"]=stream_socket_get_name($client,True);
        $new_connection["state"]="CONNECTING";
        $new_connection["ping_time"]=False;
        $new_connection["pong_time"]=False;
        $connections[$client_key]=$new_connection;
        show_message("client connected");
        if (function_exists("ws_server_connect")==True)
        {
          ws_server_connect($connections,$connections[$client_key],$client_key);
        }
      }
      else
      {
        $client_key=array_search($read[$read_key],$sockets,True);
        $data="";
        do
        {
          $buffer=fread($sockets[$client_key],1024);
          if ($buffer===False)
          {
            show_message("socket socket $client_key read error",True);
            close_client($client_key);
            continue 2;
          }
          $data.=$buffer;
        }
        while (strlen($buffer)>0);
        if (strlen($data)==0)
        {
          $connections[$client_key]["state"]="REMOTE TERMINATED";
          show_message("client socket $client_key terminated connection",True);
          close_client($client_key);
          continue;
        }
        if (on_msg($client_key,$data)=="quit")
        {
          $shutdown_flag=True;
          foreach ($sockets as $key => $socket)
          {
            if ($sockets[$key]!==$server)
            {
              close_client($key,1001,"server shutting down");
            }
          }
          break 2;
        }
      }
    }
  }
  else
  {
    foreach ($connections as $client_key => $connection)
    {
      if ($connections[$client_key]["state"]<>"OPEN")
      {
        continue;
      }
      if (($connections[$client_key]["ping_time"]!==False) and ($connections[$client_key]["pong_time"]!==False))
      {
        $delta=$connections[$client_key]["pong_time"]-$connections[$client_key]["ping_time"];
        if ($delta>WEBSOCKET_CONNECTION_TIMEOUT_SEC)
        {
          show_message("client latency is ".$delta." sec, which exceeds limit - closing connection",True);
          close_client($client_key);
          continue;
        }
        else
        {
          $delta=microtime(True)-$connections[$client_key]["ping_time"];
          if ($delta>WEBSOCKET_CONNECTION_TIMEOUT_SEC)
          {
            $connections[$client_key]["pong_time"]=False;
            $connections[$client_key]["ping_time"]=False;
          }
        }
      }
      else
      {
        $connections[$client_key]["ping_time"]=microtime(True);
        $ping_frame=encode_frame(9);
        do_reply($client_key,$ping_frame);
        #show_message("pinging client ".$client_key,True);
      }
    }
  }
}
if (function_exists("ws_server_shutdown")==True)
{
  ws_server_shutdown($server,$sockets,$connections);
}
foreach ($sockets as $key => $socket)
{
  if ($sockets[$key]!==$server)
  {
    close_client($key);
  }
}

stream_socket_shutdown($server,STREAM_SHUT_RDWR);
fclose($server);

fclose($xhr_pipe);
unlink(WEBSOCKET_XHR_PIPE_FILE);

if (function_exists("ws_server_finalize")==True)
{
  ws_server_finalize();
}

show_message("<<<<<<<<<<<<<<<<<<<<<<<<<<<<<< PUSH SERVER STOPPED >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>");

#####################################################################################################

function error_handler($errno,$errstr,$errfile,$errline)
{
  $msg="ERROR HANDLER >>> $errstr in $errfile on line $errline";
  show_message($msg);
  send_email(ADMINISTRATOR_EMAIL,"WEBSOCKET SERVER ERROR",$msg);
  die;
}

#####################################################################################################

function on_msg($client_key,$data)
{
  global $sockets;
  global $connections;
  if (function_exists("ws_server_read")==True)
  {
    $result=ws_server_read($connections,$connections[$client_key],$client_key,$data);
    if ($result!=="")
    {
      return $result;
    }
  }
  if ($connections[$client_key]["state"]=="CONNECTING")
  {
    # TODO: CHECK "Host" HEADER (COMPARE TO SERVER CONFIG SETTING)
    # TODO: CHECK "Origin" HEADER (COMPARE TO SERVER CONFIG SETTING)
    # TODO: CHECK "Sec-WebSocket-Version" HEADER (MUST BE 13)
    show_message("from client socket $client_key (connecting):",True);
    show_message(var_dump_to_str($data));
    $headers=extract_headers($data);
    $cookies=get_header($headers,"Cookie");
    if ($cookies===False)
    {
      show_message("client socket $client_key login cookie not found",True);
      close_client($client_key);
      return "";
    }
    if (ws_server_authenticate($connections[$client_key],$cookies)==False)
    {
      show_message("authentication error",True);
      close_client($client_key);
      return "";
    }
    $sec_websocket_key=get_header($headers,"Sec-WebSocket-Key");
    $sec_websocket_accept=base64_encode(sha1($sec_websocket_key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",True));
    $msg="HTTP/1.1 101 Switching Protocols".PHP_EOL;
    $msg.="Server: ".WEBSOCKET_SERVER_HEADER.PHP_EOL;
    $msg.="Upgrade: websocket".PHP_EOL;
    $msg.="Connection: Upgrade".PHP_EOL;
    $msg.="Sec-WebSocket-Accept: ".$sec_websocket_accept."\r\n\r\n";
    show_message("client socket $client_key state set to OPEN",True);
    $connections[$client_key]["state"]="OPEN";
    $connections[$client_key]["buffer"]=array();
    do_reply($client_key,$msg);
    if (function_exists("ws_server_open")==True)
    {
      ws_server_open($connections,$connections[$client_key],$client_key);
    }
  }
  elseif ($connections[$client_key]["state"]=="OPEN")
  {
    $frame=decode_frame($data);
    if ($frame===False)
    {
      show_message("received illegal frame from client socket $client_key",True);
      close_client($client_key);
      return "";
    }
    $msg="";
    switch ($frame["opcode"])
    {
      case 0: # continuation frame
        show_message("received continuation frame from client socket $client_key",True);
        $connections[$client_key]["buffer"][]=$frame;
        if ($frame["fin"]==True)
        {
          $msg=coalesce_frames($connections[$client_key]["buffer"]);
          $connections[$client_key]["buffer"]=array();
          break;
        }
        break;
      case 1: # text frame
        if ($frame["fin"]==True)
        {
          # received single text frame
          $msg=$frame["payload"];
          $connections[$client_key]["buffer"]=array();
        }
        else
        {
          # received initial frame of a fragmented series
          $connections[$client_key]["buffer"][]=$frame;
        }
        break;
      case 8: # connection close
        if (isset($frame["close_status"])==True)
        {
          show_message("received close frame - status code ".$frame["close_status"],True);
          close_client($client_key,1000);
        }
        else
        {
          show_message("received close frame from client socket $client_key - unrecognised/missing status code",True);
          close_client($client_key);
        }
        return "";
      case 9: # ping
        $reply_frame=encode_frame(10,$frame["payload"]);
        do_reply($client_key,$reply_frame);
        return "";
      case 10: # pong
        if ($connections[$client_key]["ping_time"]!==False)
        {
          $connections[$client_key]["pong_time"]=microtime(True);
          #show_message("received pong from client ".$client_key,True);
        }
        return "";
      default:
        show_message("ignored frame with unsupported opcode from client socket $client_key",True);
        return "";
    }
    if (($msg<>"") and (function_exists("ws_server_text")==True))
    {
      ws_server_text($connections,$connections[$client_key],$client_key,$msg);
    }
  }
  return "";
}

#####################################################################################################

function close_client($client_key,$status_code=False,$reason="")
{
  global $sockets;
  global $connections;
  if (function_exists("ws_server_before_close")==True)
  {
    ws_server_before_close($connections,$connections[$client_key]);
  }
  if ($status_code!==False)
  {
    show_message("closing client socket $client_key connection (cleanly)",True);
    $reply_frame=encode_frame(8,$reason,$status_code);
    do_reply($client_key,$reply_frame);
  }
  else
  {
    show_message("closing client socket $client_key connection (uncleanly)",True);
  }
  stream_socket_shutdown($sockets[$client_key],STREAM_SHUT_RDWR);
  fclose($sockets[$client_key]);
  unset($sockets[$client_key]);
  unset($connections[$client_key]);
  if (function_exists("ws_server_after_close")==True)
  {
    ws_server_after_close($connections);
  }
}

#####################################################################################################

function broadcast_to_all($msg)
{
  global $connections;
  foreach ($connections as $key => $conn)
  {
    if ($conn["state"]=="OPEN")
    {
      show_message("sending to client socket ".$key.": ".$msg,True);
      $frame=encode_text_data_frame($msg);
      do_reply($key,$frame);
    }
  }
}

#####################################################################################################

function send_text($client_key,$msg)
{
  show_message("sending to client socket ".$client_key.": ".$msg,False);
  $frame=encode_text_data_frame($msg);
  do_reply($client_key,$frame);
  return True;
}

#####################################################################################################

function encode_text_data_frame($payload)
{
  return encode_frame(1,$payload);
}

#####################################################################################################

function encode_frame($opcode,$payload="",$status=False)
{
  $length=strlen($payload);
  if ($status!==False)
  {
    $length+=2;
  }
  $frame=chr(128|$opcode);
  if ($length<=125)
  {
    $frame.=chr($length);
  }
  elseif ($length<=65535)
  {
    $frame.=chr(126);
    $frame.=chr($length>>8);
    $frame.=chr($length&255);
  }
  else
  {
    $frame.=chr(127);
    $frame.=chr($length>>56);
    $frame.=chr(($length>>48)&255);
    $frame.=chr(($length>>40)&255);
    $frame.=chr(($length>>32)&255);
    $frame.=chr(($length>>24)&255);
    $frame.=chr(($length>>16)&255);
    $frame.=chr(($length>>8)&255);
    $frame.=chr($length&255);
  }
  if ($status!==False)
  {
    $frame.=chr($status>>8);
    $frame.=chr($status&255);
  }
  $frame.=$payload;
  return $frame;
}

#####################################################################################################

function coalesce_frames(&$buffer)
{
  $msg="";
  for ($i=0;$i<count($buffer);$i++)
  {
    $msg.=$buffer[$i]["payload"];
  }
  return $msg;
}

#####################################################################################################

function decode_frame(&$frame_data)
{
  # https://tools.ietf.org/html/rfc6455
  $frame=array();
  $F=unpack("C".min(14,strlen($frame_data)),$frame_data); # first key is 1 (not 0)
  $frame["fin"]=(($F[1]&128)==128);
  $frame["opcode"]=$F[1]&15;
  $frame["mask"]=(($F[2]&128)==128);
  $length=$F[2]&127;
  $L=0; # number of additional bytes for payload length
  if ($length==126)
  {
    # pack 16-bit network byte ordered (big-endian) unsigned int
    $length=($F[3]<<8)+$F[4];
    $L=2;
  }
  elseif ($length==127)
  {
    # pack 64-bit network byte ordered (big-endian) unsigned int
    $length=($F[3]<<56)+($F[4]<<48)+($F[5]<<40)+($F[6]<<32)+($F[7]<<24)+($F[8]<<16)+($F[9]<<8)+$F[10];
    $L=8;
  }
  $frame["mask_key"]=array();
  $offset=2+$L+1; # first payload byte (no mask)
  if ($frame["mask"]==True)
  {
    for ($i=1;$i<=4;$i++)
    {
      $frame["mask_key"][]=$F[2+$L+$i];
    }
    $offset+=4; # first payload byte (with mask)
  }
  $frame["payload"]="";
  if ($length>0)
  {
    if ($frame["mask"]==True)
    {
      for ($i=0;$i<$length;$i++)
      {
        $key=$i+$offset-1;
        if (isset($frame_data[$key])==False)
        {
          show_message("decode_frame error: frame_data[key] not found: key=".$key);
          return False;
        }
        $frame_data[$key]=chr(ord($frame_data[$key])^$frame["mask_key"][$i%4]);
      }
    }
    $frame["payload"]=substr($frame_data,$offset-1);
    if (($frame["opcode"]==8) and ($length>=2))
    {
      $status=unpack("C2",$frame["payload"]);
      $frame["close_status"]=($status[1]<<8)+$status[2];
      $frame["payload"]=substr($frame["payload"],2);
    }
    # received invalid utf8 frame (according to following preg_match) when sending a basic json-encoded string so there may be a sneaky bug somewhere in this function
    # works (length=92): {"operation":"doc_record_lock","client_id":"clientid_588c81e742a3a","doc_id":"","foo":"bar"}
    # works (length=80): {"operation":"doc_record_lock","client_id":"clientid_588c82769e6c9","doc_id":""}
    # fails (length=85): {"operation":"doc_record_lock","client_id":"clientid_588c83b2c3d1d","doc_id":"32458"} <== string is decoded correctly but 91 chars of jibberish appears after (readable using ISO-8859-14 encoding with mousepad)
    /*$valid_utf8=preg_match("//u",$frame["payload"]);
    if (($valid_utf8===False) or ($valid_utf8==0))
    {
      show_message("decode_frame error: invalid utf8 found in payload");
      show_message($frame["payload"]);
      return False;
    }*/
    # workaround for now is to loop through payload and truncate before first invalid ascii character, which seems to work for the failing data (using ord function doesn't work)
    $valid=" !\"#$%&'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\]^_`abcdefghijklmnopqrstuvwxyz{|}~…‘’“”•–—™¢€£§©«®°±²³´µ¶·¹»¼½¾";
    for ($i=0;$i<strlen($frame["payload"]);$i++)
    {
      $c=$frame["payload"][$i];
      if (strpos($valid,$c)!==False)
      {
        continue;
      }
      $frame["payload"]=substr($frame["payload"],0,$i);
      show_message("decode_frame warning: payload truncated");
      break;
    }
  }
  return $frame;
}

#####################################################################################################

function do_reply($client_key,$msg) # $msg is an encoded websocket frame
{
  global $sockets;
  global $connections;
  if ($connections[$client_key]["state"]<>"OPEN")
  {
    return False;
  }
  $total_sent=0;
  while ($total_sent<strlen($msg))
  {
    $buf=substr($msg,$total_sent);
    try
    {
      $written=fwrite($sockets[$client_key],$buf,min(strlen($buf),8192));
    }
    catch (Exception $e)
    {
      $err_msg="an exception occurred when attempting to write to client socket $client_key";
      show_message($err_msg,True);
      send_email(ADMINISTRATOR_EMAIL,"WEBSOCKET SERVER EXCEPTION",$err_msg);
      close_client($client_key);
      return;
    }
    if (($written===False) or ($written<=0))
    {
      show_message("error writing to client socket $client_key",True);
      close_client($client_key);
      return;
    }
    $total_sent+=$written;
  }
}

#####################################################################################################

function show_message($msg,$star=False)
{
  $prefix="";
  if ($star==True)
  {
    $prefix="*** ";
  }
  $output="[".date("Y-m-d H:i:s")."]  ".$prefix.$msg.PHP_EOL;
  echo $output;
  file_put_contents(WEBSOCKET_LOG_PATH."ws_".date("Ymd").".log",$output,FILE_APPEND);
}

#####################################################################################################

function var_dump_to_str($var)
{
  ob_start();
  var_dump($var);
  return ob_get_clean();
}

#####################################################################################################

function shutdown_handler()
{
  global $shutdown_flag;
  $status=shell_exec("sudo systemctl status push_server.service");
  $stopmsg="Active: deactivating (stop-sigterm)";
  if (strpos($status,$stopmsg)!==False)
  {
    show_message("<<< SYSTEMCTL STOP COMMAND DETECTED - TERMINATING >>>");
    die;
  }
  if ($shutdown_flag==True)
  {
    show_message("<<< SHUTDOWN FLAG SET - TERMINATING >>>");
    die;
  }
  send_email(ADMINISTRATOR_EMAIL,"WEBSOCKET SERVER RESTART","");
  show_message("<<< RESTARTING SERVER >>>");
  shell_exec("sudo systemctl restart push_server.service");
  die;
}

#####################################################################################################

function signal_handler($signo)
{
  # required for shutdown handler to be called on systemctl stop
}

#####################################################################################################
