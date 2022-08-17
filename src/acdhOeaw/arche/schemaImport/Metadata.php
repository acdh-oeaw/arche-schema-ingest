<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
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

namespace acdhOeaw\arche\schemaImport;

use EasyRdf\Graph;
use EasyRdf\Resource;
use EasyRdf\Literal;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use zozlak\RdfConstants AS RDF;

/**
 * Description of Metadata
 *
 * @author zozlak
 */
class Metadata {

    static public function fromFile(string $file): Resource {
        $graph = new Graph();
        $graph->parseFile($file);
        foreach ($graph->resources() as $res) {
            if (count($res->propertyUris()) > 0) {
                return $res;
            }
        }
        throw RuntimeException("No valid graph node found");
    }

    static public function enrichFromArgs(Resource $meta, object $schema,
                                          object $args): void {
        if (!empty($args->ontologyDate)) {
            $meta->addLiteral($schema->dateStart, new Literal($args->ontologyDate, null, RDF::XSD_DATE_TIME));
            $meta->addLiteral($schema->dateEnd, new Literal($args->ontologyDate, null, RDF::XSD_DATE_TIME));
        }
        if (!empty($args->ontologyUrl)) {
            $meta->addLiteral($schema->url, new Literal($args->ontologyUrl, null, RDF::XSD_ANY_URI));
        }
        if (!empty($args->ontologyVersion)) {
            $meta->addLiteral($schema->version, $args->ontologyVersion);
        }
        if (!empty($args->ontologyInfo)) {
            $meta->addLiteral($schema->info, new Literal($args->ontologyInfo, 'und'));
        }
    }

    static public function enrichFromComposer(Resource $meta, object $schema,
                                              string $package): string {
        $version = \Composer\InstalledVersions::getPrettyVersion($package);
        $meta->addLiteral($schema->version, $version);

        $dir         = \Composer\InstalledVersions::getInstallPath($package);
        $packageMeta = json_decode(file_get_contents("$dir/composer.json"));
        if ($packageMeta->homepage ?? false) {
            $baseUrl = $packageMeta->homepage;
            $meta->addLiteral($schema->url, new Literal("$baseUrl/releases/$version", null, RDF::XSD_ANY_URI));
        }

        $repoPath = explode('/', $baseUrl);
        $repoPath = implode('/', array_slice($repoPath, count($repoPath) - 2));
        $client   = new Client(['http_errors' => false]);
        $headers  = ['Accept' => 'application/json'];
        $resp     = $client->send(new Request('get', "https://api.github.com/repos/$repoPath/releases/tags/$version", $headers));
        if ($resp->getStatusCode() === 200) {
            $data = json_decode($resp->getBody());
            $meta->addLiteral($schema->dateStart, new Literal($data->published_at, null, RDF::XSD_DATE_TIME));
            $meta->addLiteral($schema->dateEnd, new Literal($data->published_at, null, RDF::XSD_DATE_TIME));

            if (!empty($data->info)) {
                $meta->addLiteral($schema->infor, new Literal(trim($data->body), 'und'));
            }
        }
        
        return "$dir/acdh-schema.owl";
    }
}
