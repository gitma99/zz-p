<?php
function write_file($content, $path, $json_encode = False){
    if ($json_encode){
        file_put_contents($path, json_encode($content, JSON_PRETTY_PRINT));
    } else {
        file_put_contents($path, $content);
    }
    return null;
}
?>