<?php
// Prepares the OpenAPI document before generation: drops the `Idempotency-Key` header parameter of every operation,
// which the client sets itself (the same key on every retry of a call; ControlResell::idempotent() chooses it).
//
//   php scripts/prepare-spec.php <in.json> <out.json>
declare(strict_types=1);

[$_, $in, $out] = $argv;
$spec = json_decode((string) file_get_contents($in), false, 512, JSON_THROW_ON_ERROR);
foreach ($spec->paths as $path) {
    foreach ($path as $method => $operation) {
        if (!is_object($operation) || !isset($operation->parameters)) {
            continue;
        }
        $operation->parameters = array_values(array_filter(
            $operation->parameters,
            static fn ($p) => !(isset($p->in, $p->name) && $p->in === 'header' && strcasecmp($p->name, 'Idempotency-Key') === 0),
        ));
        if ($operation->parameters === []) {
            unset($operation->parameters);
        }
    }
}
file_put_contents($out, json_encode($spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
