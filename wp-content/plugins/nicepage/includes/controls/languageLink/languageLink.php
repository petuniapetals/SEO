<?php
defined('ABSPATH') or die;

if (isset($controlProps) && isset($controlTemplate)) {
    $language = isset($controlProps['lang']) && $controlProps['lang'] ? $controlProps['lang'] : false;
    $url = NpMultiLanguages::getLanguageLink($language);
    $controlTemplate = str_replace('[[url]]', $url, $controlTemplate);
    $controlTemplate = str_replace('[[lang]]', $controlProps['lang'], $controlTemplate);
    $controlTemplate = str_replace('[[langText]]', $controlProps['langText'], $controlTemplate);
    echo $controlTemplate;
}