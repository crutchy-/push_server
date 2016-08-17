<?php

#####################################################################################################

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("UTC");
ini_set("memory_limit","512M");

define("LISTENING_ADDRESS","127.0.0.1");
define("LISTENING_PORT",50000);
define("SELECT_TIMEOUT",200000); # microseconds (0.2 seconds)
define("SERVER_HEADER","SimpleWS/0.1");

define("PIPE_FILE","../data/notify_iface");

require_once("../push_server_events.php"); # contains functions (or includes another file that contains functions) to handle events for the specific application
/*
  optional event handlers:
  function ws_server_fifo_read(&$server,&$sockets,&$connections,$fifo_data)
  function ws_server_loop(&$server,&$sockets,&$connections)
  function ws_server_open(&$connection)
  function ws_server_close(&$connection)
  function ws_server_text(&$connection,&$frame)
  function ws_server_ping(&$connection,&$frame)
  function ws_server_shutdown(&$server,&$sockets,&$connections)
*/

set_error_handler("error_handler");

if (isset($argv[1])==True)
{
  if ($argv[1]=="test")
  {
    run_all_tests();
    return;
  }
}

if (file_exists(PIPE_FILE)==True)
{
  unlink(PIPE_FILE);
}
umask(0);
if (posix_mkfifo(PIPE_FILE,0666)==False)
{
  show_message("ERROR: UNABLE TO MAKE FIFO NAMED PIPE FILE",True);
  return;
}
$pipe=fopen(PIPE_FILE,"r+");
stream_set_blocking($pipe,0);

$sockets=array();
$connections=array();
$server=stream_socket_server("tcp://".LISTENING_ADDRESS.":".LISTENING_PORT,$err_no,$err_msg);
if ($server===False)
{
  show_message("could not bind to socket: ".$err_msg,True);
  return;
}
show_message("push_server started");
stream_set_blocking($server,0);
$sockets[]=$server;
while (True)
{
  $read=array($pipe);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,SELECT_TIMEOUT);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets($pipe));
      if (function_exists("ws_server_shutdown")==True)
      {
        ws_server_fifo_read($server,$sockets,$connections,$data);
      }
    }
  }
  $read=array(STDIN);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,SELECT_TIMEOUT);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets(STDIN));
      if ($data=="q")
      {
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
  $change_count=stream_select($read,$write,$except,0,SELECT_TIMEOUT);
  if ($change_count===False)
  {
    show_message("stream_select failed",True);
    break;
  }
  if ($change_count<1)
  {
    continue;
  }
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
      $new_connection["client_id"]="";
      $new_connection["client_id_confirmed"]=False;
      $new_connection["state"]="CONNECTING";
      $connections[$client_key]=$new_connection;
      show_message("client connected");
    }
    else
    {
      $client_key=array_search($read[$read_key],$sockets,True);
      $data="";
      do
      {
        $buffer=fread($sockets[$client_key],8);
        if (strlen($buffer)===False)
        {
          show_message("read error",True);
          close_client($client_key);
          continue 2;
        }
        $data.=$buffer;
      }
      while (strlen($buffer)>0);
      if (strlen($data)==0)
      {
        show_message("client terminated connection",True);
        close_client($client_key);
        continue;
      }
      on_msg($client_key,$data);
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

fclose($pipe);
unlink(PIPE_FILE);

#####################################################################################################

function error_handler($errno,$errstr,$errfile,$errline)
{
  $continue_errors=array(
    "failed with errno=32 Broken pipe",
    "failed with errno=104 Connection reset by peer");
  for ($i=0;$i<count($continue_errors);$i++)
  {
    if (strpos($errstr,$continue_errors[$i])!==False)
    {
      return True;
    }
  }
  echo "*** $errstr in $errfile on line $errline".PHP_EOL;
  die; # FOR TEST/DEBUG
}

#####################################################################################################

function on_msg($client_key,$data)
{
  global $sockets;
  global $connections;
  if ($connections[$client_key]["state"]=="CONNECTING")
  {
    # TODO: CHECK "Host" HEADER (COMPARE TO CONFIG SETTING)
    # TODO: CHECK "Origin" HEADER (COMPARE TO CONFIG SETTING)
    # TODO: CHECK "Sec-WebSocket-Version" HEADER (MUST BE 13)
    show_message("from client:",True);
    var_dump($data);
    $headers=extract_headers($data);
    $sec_websocket_key=get_header($headers,"Sec-WebSocket-Key");
    $sec_websocket_accept=base64_encode(sha1($sec_websocket_key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",True));
    $msg="HTTP/1.1 101 Switching Protocols".PHP_EOL;
    $msg.="Server: ".SERVER_HEADER.PHP_EOL;
    $msg.="Upgrade: websocket".PHP_EOL;
    $msg.="Connection: Upgrade".PHP_EOL;
    $msg.="Sec-WebSocket-Accept: ".$sec_websocket_accept."\r\n\r\n";
    show_message("to client:",True);
    var_dump($msg);
    $connections[$client_key]["state"]="OPEN";
    $connections[$client_key]["buffer"]=array();
    do_reply($client_key,$msg);
    if (function_exists("ws_server_open")==True)
    {
      ws_server_open($connections[$client_key]);
    }
    $connections[$client_key]["client_id"]=uniqid("clientid_");
    $params=array("client_id"=>$connections[$client_key]["client_id"]);
    $json=json_encode($params,JSON_PRETTY_PRINT);
    $frame=encode_text_data_frame($json);
    do_reply($client_key,$frame);
  }
  elseif ($connections[$client_key]["state"]=="OPEN")
  {
    $frame=decode_frame($data);
    if ($frame===False)
    {
      # illegal frame
      close_client($client_key);
      return;
    }
    $msg="";
    switch ($frame["opcode"])
    {
      case 0: # continuation frame
        show_message("received continuation frame",True);
        $connections[$client_key]["buffer"][]=$frame;
        if ($frame["fin"]==True)
        {
          $msg=coalesce_frames($connections[$client_key]["buffer"]);
          $connections[$client_key]["buffer"]=array();
          break;
        }
        else
        {
          return;
        }
      case 1: # text frame
        if ($frame["fin"]==True)
        {
          show_message("received text frame",True);
        }
        else
        {
          show_message("received initial text frame of a fragmented series",True);
        }
        $connections[$client_key]["buffer"]=array();
        $msg=$frame["payload"];
        $data=json_decode($msg,True);
        if (isset($data["client_id_confirm"])==True)
        {
          if ($data["client_id_confirm"]===$connections[$client_key]["client_id"])
          {
            $connections[$client_key]["client_id_confirmed"]=True;
          }
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
          show_message("received close frame - invalid/missing status code ",True);
          close_client($client_key);
        }
        return;
      case 9: # ping
        show_message("received ping frame",True);
        $reply_frame=encode_frame(10,$frame["payload"]);
        do_reply($client_key,$reply_frame);
        if (function_exists("ws_server_ping")==True)
        {
          ws_server_ping($connections[$client_key],$frame);
        }
        return;
      case 10: # pong
        show_message("received unsolicited pong frame",True);
        return;
      default:
        show_message("received frame with unsupported opcode - terminating connection",True);
        close_client($client_key);
        return;
    }
    if (function_exists("ws_server_text")==True)
    {
      ws_server_text($connections[$client_key],$frame);
    }
    $reply_frame=encode_text_data_frame($msg);
    do_reply($client_key,$reply_frame);
  }
}

#####################################################################################################

function close_client($client_key,$status_code=False,$reason="")
{
  global $sockets;
  global $connections;
  if (function_exists("ws_server_close")==True)
  {
    ws_server_close($connections[$client_key]);
  }
  if ($status_code!==False)
  {
    show_message("closing client connection (cleanly)",True);
    $reply_frame=encode_frame(8,$reason,$status_code);
    do_reply($client_key,$reply_frame);
  }
  else
  {
    show_message("closing client connection (uncleanly)",True);
  }
  stream_socket_shutdown($sockets[$client_key],STREAM_SHUT_RDWR);
  fclose($sockets[$client_key]);
  unset($sockets[$client_key]);
  unset($connections[$client_key]);
}

#####################################################################################################

function send_text($client_id,$msg)
{
  global $connections;
  if ($client_id=="")
  {
    show_message("SEND TEXT ERROR: INVALID CLIENT ID",True);
    return False;
  }
  $client_key=False;
  foreach ($connections as $key => $conn)
  {
    if (($conn["client_id"]===$client_id) and ($conn["client_id_confirmed"]==True))
    {
      $client_key=$key;
      break;
    }
  }
  if ($client_key===False)
  {
    show_message("SEND TEXT ERROR: REMOTE NOT FOUND",True);
    return False;
  }
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
    if (preg_match("//u",$frame["payload"])==0)
    {
      return False;
    }
  }
  return $frame;
}

#####################################################################################################

function do_reply($client_key,&$msg)
{
  global $sockets;
  $total_sent=0;
  while ($total_sent<strlen($msg))
  {
    $buf=substr($msg,$total_sent);
    $written=fwrite($sockets[$client_key],$buf,min(strlen($buf),8192));
    if (($written===False) or ($written<=0))
    {
      show_message("error writing to client socket",True);
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
  echo $prefix.$msg.PHP_EOL;
}

#####################################################################################################

function extract_headers($response)
{
  $delim="\r\n\r\n";
  $i=strpos($response,$delim);
  if ($i===False)
  {
    return False;
  }
  $headers=substr($response,0,$i);
  return explode(PHP_EOL,$headers);
}

#####################################################################################################

function get_header($lines,$header)
{
  for ($i=0;$i<count($lines);$i++)
  {
    $line=trim($lines[$i]);
    $parts=explode(":",$line);
    if (count($parts)>=2)
    {
      $key=trim(array_shift($parts));
      $value=trim(implode(":",$parts));
      if (strtolower($key)==strtolower($header))
      {
        return $value;
      }
    }
  }
  return False;
}

#####################################################################################################

function run_all_tests()
{
  $lengths=array(0,1,5,124,125,126,127,128,129,130,65533,65534,65535,65536,65537,66000,100000000);
  for ($i=0;$i<count($lengths);$i++)
  {
    show_message("running test $i");
    $payload=str_repeat("*",$lengths[$i]);
    $encoded=encode_text_data_frame($payload);
    $decoded=decode_frame($encoded);
    if ($decoded["payload"]<>$payload)
    {
      show_message("test failed ($i)",True);
      var_dump($payload);
      var_dump($encoded);
      var_dump($decoded);
      return;
    }
  }
}

#####################################################################################################
