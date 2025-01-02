<?php
function open_file($path, $parse_as_json = False){
    $opend_file_content = file_get_contents($path);
    if (is_string($opend_file_content)){
        if ($parse_as_json){
            return [json_decode($opend_file_content, true), false];
        } else {
            return [$opend_file_content, false];
        }
    } else {
        return [false , "Error When openning file at '{$path}'!"];
    }
}
?>