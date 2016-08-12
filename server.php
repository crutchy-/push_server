<?php

#####################################################################################################

# for help with ssl server: http://php.net/manual/en/function.stream-socket-server.php#118419

# for testing: https://github.com/minimaxir/big-list-of-naughty-strings/blob/master/blns.txt

#####################################################################################################

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("UTC");

if (isset($argv[1])==True)
{
  switch ($argv[1])
  {
    case "test":
      run_all_tests();
      return;
  }
}

$sockets=array();
$connections=array();
$server=stream_socket_server("tcp://127.0.0.1:9001",$err_no,$err_msg);
if ($server===False)
{
  show_message("could not bind to socket: ".$err_msg,True);
  return;
}
stream_set_blocking($server,0);
$sockets[]=$server;
while (True)
{
  $read=array(STDIN);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,200000);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets(STDIN));
      if ($data=="q")
      {
        break;
      }
    }
  }
  $read=$sockets;
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,200000);
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
      $client=stream_socket_accept($server);
      stream_set_blocking($client,0);
      $sockets[]=$client;
      $client_key=array_search($client,$sockets,True);
      $new_connection=array();
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
foreach ($sockets as $key => $socket)
{
  if ($sockets[$key]!==$server)
  {
    close_client($key);
  }
}

stream_socket_shutdown($server,STREAM_SHUT_RDWR);
fclose($server);

#####################################################################################################

function close_client($client_key)
{
  global $sockets;
  global $connections;
  stream_socket_shutdown($sockets[$client_key],STREAM_SHUT_RDWR);
  fclose($sockets[$client_key]);
  unset($sockets[$client_key]);
  unset($connections[$client_key]);
}

#####################################################################################################

function on_msg($client_key,$data)
{
  global $connections;
  show_message("from client:",True);
  if ($connections[$client_key]["state"]=="CONNECTING")
  {
    # TODO: CHECK "Host" HEADER (COMPARE TO CONFIG SETTING)
    # TODO: CHECK "Origin" HEADER (COMPARE TO CONFIG SETTING)
    # TODO: CHECK "Sec-WebSocket-Version" HEADER (MUST BE 13)
    var_dump($data);
    $headers=extract_headers($data);
    $sec_websocket_key=get_header($headers,"Sec-WebSocket-Key");
    $sec_websocket_accept=base64_encode(sha1($sec_websocket_key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",True));
    $msg="HTTP/1.1 101 Switching Protocols".PHP_EOL;
    $msg.="Upgrade: websocket".PHP_EOL;
    $msg.="Connection: Upgrade".PHP_EOL;
    $msg.="Sec-WebSocket-Accept: ".$sec_websocket_accept."\r\n\r\n";
    show_message("to client:",True);
    var_dump($msg);
    $connections[$client_key]["state"]="OPEN";
    $connections[$client_key]["buffer"]=array();
    do_reply($client_key,$msg);
  }
  elseif ($connections[$client_key]["state"]=="OPEN")
  {
    $frame=decode_frame($data);
    $msg="";
    switch ($frame["opcode"])
    {
      case 0: # continuation frame
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
        $msg=$frame["payload"];
        break;
      case 8: # connection close
        close_client($client_key);
        return;
      case 9: # ping
        # TODO: SEND PONG FRAME
        return;
      default:
        # TODO: SEND CLOSE FRAME
        close_client($client_key);
        return;
    }
    var_dump($msg);
    do_reply($client_key,$msg);
  }
}

#####################################################################################################

function encode_text_data_frame($payload)
{

}

#####################################################################################################

function encode_control_frame($opcode,$payload)
{

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

function decode_frame($frame_data)
{
  # https://tools.ietf.org/html/rfc6455
  $frame=array();
  $F=unpack("C*",$frame_data);
  #var_dump($F);
  $frame["raw"]=$frame_data;
  $frame["fin"]=(($F[1] & 128)==128);
  $frame["opcode"]=$F[1] & 15;
  $frame["mask"]=(($F[2] & 128)==128);
  $frame["length"]=$F[2] & 127;
  $L=0; # number of additional bytes for payload length
  if ($frame["length"]==126)
  {
    # pack 16-bit network byte ordered (big-endian) unsigned int
    $frame["length"]=($F[3]<<8)+$F[4];
    $L=2;
  }
  elseif ($frame["length"]==127)
  {
    # pack 64-bit network byte ordered (big-endian) unsigned int
    $frame["length"]=($F[3]<<56)+($F[4]<<48)+($F[5]<<40)+($F[6]<<32)+($F[7]<<24)+($F[8]<<16)+($F[9]<<8)+$F[10];
    $L=8;
  }
  $frame["mask_key"]=array();
  $N=2+$L+1; # first payload byte (no mask)
  if ($frame["mask"]==True)
  {
    for ($i=1;$i<=4;$i++)
    {
      $frame["mask_key"][]=$F[2+$L+$i];
    }
    $N+=4; # first payload byte (with mask)
  }
  $frame["masked_text"]=substr($frame_data,$N-1,$frame["length"]);
  $frame["masked_bytes"]=array_values(array_slice($F,$N-1,$frame["length"]));
  $frame["payload"]="";
  if ($frame["mask"]==True)
  {
    $frame["payload"]=$frame["masked_bytes"];
    for ($i=0;$i<$frame["length"];$i++)
    {
      $frame["payload"][$i]=$frame["payload"][$i]^$frame["mask_key"][$i%4];
      $frame["payload"][$i]=chr($frame["payload"][$i]);
    }
    $frame["payload"]=implode("",$frame["payload"]);
  }
  return $frame;
}

#####################################################################################################

function do_reply($client_key,$msg)
{
  global $sockets;
  fwrite($sockets[$client_key],$msg);
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
  $client=stream_socket_client("tcp://127.0.0.1:50000",$err_no,$err_msg);
  if ($client===False)
  {
    show_message("error connecting: ".$err_msg,True);
    return;
  }
  $tests=array("hello","a b","a\na"," ");
  for ($i=0;$i<count($tests);$i++)
  {
    fwrite($client,$tests[$i]);
    $data=fread($client,1024);
    show_message("reply: ".$data);
    if ($data==$tests[$i])
    {
      show_message("[SUCCESS] reply: ".$data);
    }
    else
    {
      show_message("[FAIL] reply: ".$data);
    }
    sleep(1);
  }
  stream_socket_shutdown($client,STREAM_SHUT_RDWR);
  fclose($client);
}

#####################################################################################################
