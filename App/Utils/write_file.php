<?php
function write_file($content, $file_path, $json_encode = False){
    // if (!is_file($path)){
    //     return "NOT A FILE PATH!";
    // }
    if ($json_encode){
        return file_put_contents($file_path, json_encode($content, JSON_PRETTY_PRINT));
    } else {
        return file_put_contents($file_path, $content);
    }
}
?>