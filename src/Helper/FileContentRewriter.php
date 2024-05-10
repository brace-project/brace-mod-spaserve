<?php

namespace Brace\SpaServe\Helper;

class FileContentRewriter
{

    public function __construct(private array $keyValues)
    {
            
    }
    
    public function rewrite(string $content) : string
    {
        foreach ($this->keyValues as $key => $value) {
            $content = str_replace($key, $value, $content);
        }
        return $content;
    }
    
}