<?php
function mask_review_profanity(string $text): string
{
    $pattern = '/(?<![\p{L}\p{N}])(?:'
    . 'f[u*@]+c?k+|fck|fuk|fuq|'
    . 'sh[i1!]+t+|sht|'
    . 'b[i1!]+tch|'
    . 'asshole|bastard|damn|'
    . 'p[u*@o]+t[a@]+|'
    . 'p[u*@o]+t[a@]+ng[\s._-]*[i1!]+n[a@]+|'
    . 't[a@]+ng[\s._-]*[i1!]+n[a@]+|'
    . 'tang[\s._-]*ina|'
    . 'p[u*@]+ch[a@]+|punyeta|'
    . 'g[a@]+g[o0@]+|g[a@]+g[a@]+|'
    . 'b[o0]+b[o0]+|'
    . 't[a@]+ng[a@]+|'
    . '[u]+l[o0u]+l|'
    . 'tarantad[o0a@]+|'
    . 'k[u]+p[a@]+l|'
    . 'p[a@]+ky[u]+|p[a@]+kshet|'
    . 'bw[i1!]+s[i1!]+t|buwisit|'
    . 'lech[e3]+|letse|lintik|'
    . 'hinayupak|hay[o0]+p|hayup|ogag|'
    . ')(?![\p{L}\p{N}])/iu';
    $filtered = preg_replace($pattern, '****', $text);
    if ($filtered === null) {
        throw new RuntimeException('Review text could not be processed.');
    }
    return $filtered;
}
