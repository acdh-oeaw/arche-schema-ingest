#!/usr/bin/php
<?php
/*
 * The MIT License
 *
 * Copyright 2020 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

use acdhOeaw\arche\schemaImport\Ontology;

if ($argc < 3 || !file_exists($argv[1]) || !file_exists($argv[2])) {
    echo "Checks if an OWL ontology is valid for ARCHE.\n\n";
    echo "usage: $argv[0] config.yaml ontology.owl\n\n";
    return;
}
$cfg = json_decode(json_encode(yaml_parse_file($argv[1])));

if (isset($cfg->composerLocation)) {
    require_once $cfg->composerLocation;
} else {
    require_once __DIR__ . '/vendor/autoload.php';
}

$ontology = new Ontology($cfg->schema);
$ontology->loadFile($argv[2]);
$result   = $ontology->check($cfg->schema->namespaces->ontology);
exit($result ? 0 : 1);
