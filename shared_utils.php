<?php

#####################################################################################################

function load_files($path,$root="",$ext="",$trim_ext=True) # path (and root) must have trailing delimiter, ext excludes dot, empty ext means all
{
  if ($root=="")
  {
    $root=$path;
  }
  $result=array();
  $file_list=scandir($path);
  for ($i=0;$i<count($file_list);$i++)
  {
    $fn=$file_list[$i];
    if (($fn==".") or ($fn=="..") or ($fn==".git"))
    {
      continue;
    }
    $full=$path.$fn;
    if ($path<>$root)
    {
      $fn=substr($full,strlen($root));
    }
    if (is_dir($full)==False)
    {
      $fext=pathinfo($fn,PATHINFO_EXTENSION);
      if ($ext<>"")
      {
        if ($fext<>$ext)
        {
          continue;
        }
      }
      if ($trim_ext==True)
      {
        $fn=substr($fn,0,strlen($fn)-strlen($fext)-1);
      }
      $result[$fn]=trim(file_get_contents($full));
    }
    else
    {
      $result=$result+load_files($full."/",$root,$ext,$trim_ext);
    }
  }
  return $result;
}

#####################################################################################################

function template_fill($template_key,$params=False,$tracking=array(),$custom_templates=False) # tracking array is used internally to limit recursion and should not be manually passed
{
  global $templates;
  if ($custom_templates!==False)
  {
    $template_array=$custom_templates;
  }
  else
  {
    $template_array=$templates;
  }
  if (isset($template_array[$template_key])==False)
  {
    show_message("template \"$template_key\" not found");
  }
  if (in_array($template_key,$tracking)==True)
  {
    show_message("circular reference to template \"$template_key\"");
  }
  $tracking[]=$template_key;
  $result=$template_array[$template_key];
  foreach ($template_array as $key => $value)
  {
    if (strpos($result,"@@$key@@")===False)
    {
      continue;
    }
    $value=template_fill($key,False,$tracking);
    $result=str_replace("@@$key@@",$value,$result);
  }
  if ($params!==False)
  {
    foreach ($params as $key => $value)
    {
      if (is_array($value)==False)
      {
        $result=str_replace("%%$key%%",$value,$result);
      }
    }
  }
  return $result;
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

function mysql_to_iso_timestamp($ts)
{
  $ts=strtotime($ts);
  return date("Y-m-d",$ts)."T".date("H:i:s",$ts)."Z";
}

#####################################################################################################

function unix_to_iso_timestamp($ts)
{
  return date("Y-m-d",$ts)."T".date("H:i:s",$ts)."Z";
}

#####################################################################################################

function send_email($recipient,$subject,$message)
{
  $headers="MIME-Version: 1.0".PHP_EOL;
  $headers=$headers."Content-type: text/html; charset=iso-8859-1".PHP_EOL;
  mail($recipient,$subject,$message,$headers);
}

#####################################################################################################
