<?php
function open_file($path, $parse_as_json = False){
    if (empty($path)){
        return [false , "Error: Emptyfile at '{$path}'!"];
    }
    
    $opend_file_content = file_get_contents($path);
    if (is_string($opend_file_content)){
        if ($parse_as_json){
            $decoded = json_decode($opend_file_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [false, "Error: Invalid JSON in file at '{$path}': " . json_last_error_msg()];
            }
            return [$decoded, false];
        } else {
            return [$opend_file_content, false];
        }
    } else {
        return [false , "Error When openning file at '{$path}'!"];
    }
}
?>