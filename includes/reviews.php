
<?php
function mask_review_profanity(string $text): string
{
    $badWords = [
            'gaga', 'gage', 'gagi', 'gago', 'gagu', 'g4go','g4g0', 'gag0', 'g@go','g@gu', 'g@g0', 
            'putangina', 'potangena', 'putangena', 'potangina','putang1na', 'pota', 'p0ta', 'pucha',
            'tangena', 't@ngina', 'tang1na', 'tngina',
            'tanga','t4nga', 'tang4', 't@nga',
            'bobo','b0bo', 'bob0', 'b0b0','bubu',
            'fuck','fck', 'fuk', 'f*ck', 'f4ck', 'fvck',
            'shit','shet','sh1t' ,'sh!t', 'sht',
            'b1tch', 'b!tch', 'biatch','bitchesa','bitch',
            'pakyou', 'pak u', 'fakyu', 'pakyow','pakyu',
            'bwesit', 'bwset', 'bwis3t', 'bwct'
                ];

    foreach ($badWords as $word) {
        $pattern = '/(?<![\p{L}\p{N}])'. preg_quote($word, '/'). '(?![\p{L}\p{N}])/iu';

        $text = preg_replace_callback(
            $pattern,
            fn($match) => str_repeat('*', mb_strlen($match[0])),
            $text
        );
    }

    return $text;
}
