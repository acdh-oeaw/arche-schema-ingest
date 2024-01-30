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

use RuntimeException;
use rdfInterface\DatasetNodeInterface;
use quickRdf\DatasetNode;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use quickRdfIo\Util as RdfUtil;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use zozlak\RdfConstants AS RDF;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of Metadata
 *
 * @author zozlak
 */
class Metadata {

    static public function fromFile(string $file): DatasetNode {
        $dataset = new Dataset();
        $dataset->add(RdfUtil::parse($file, new DF()));
        if (count($dataset) === 0) {
            throw new RuntimeException("No valid graph node found");
        }
        $res = new DatasetNode($dataset->getSubject());
        return $res->withDataset($dataset);
    }

    static public function enrichFromArgs(DatasetNodeInterface $meta,
                                          Schema $schema, object $args): void {
        if (!empty($args->ontologyDate)) {
            $meta->add([
                DF::quadNoSubject($schema->dateStart, DF::literal($args->ontologyDate, null, RDF::XSD_DATE_TIME)),
                DF::quadNoSubject($schema->dateEnd, DF::literal($args->ontologyDate, null, RDF::XSD_DATE_TIME)),
            ]);
        }
        if (!empty($args->ontologyUrl)) {
            $meta->add(DF::quadNoSubject($schema->url, DF::literal($args->ontologyUrl, null, RDF::XSD_ANY_URI)));
        }
        if (!empty($args->ontologyVersion)) {
            $meta->add(DF::quadNoSubject($schema->version, DF::literal($args->ontologyVersion)));
        }
        if (!empty($args->ontologyInfo)) {
            $meta->add(DF::quadNoSubject($schema->info, DF::literal($args->ontologyInfo, 'und')));
        }
    }

    static public function enrichFromComposer(DatasetNodeInterface $meta,
                                              object $schema, string $package): string {
        $version = \Composer\InstalledVersions::getPrettyVersion($package);
        $meta->add(DF::quadNoSubject($schema->version, DF::literal($version)));

        $dir         = \Composer\InstalledVersions::getInstallPath($package);
        $packageMeta = json_decode((string) file_get_contents("$dir/composer.json"));
        if ($packageMeta->homepage ?? false) {
            $baseUrl = $packageMeta->homepage;
            $meta->add(DF::quadNoSubject($schema->url, DF::literal("$baseUrl/releases/$version", null, RDF::XSD_ANY_URI)));
        }

        $repoPath = explode('/', $baseUrl ?? '');
        $repoPath = implode('/', array_slice($repoPath, count($repoPath) - 2));
        $client   = new Client(['http_errors' => false]);
        $headers  = ['Accept' => 'application/json'];
        $resp     = $client->send(new Request('get', "https://api.github.com/repos/$repoPath/releases/tags/$version", $headers));
        if ($resp->getStatusCode() === 200) {
            $data = json_decode($resp->getBody());
            $meta->add([
                DF::quadNoSubject($schema->dateStart, DF::literal($data->published_at, null, RDF::XSD_DATE_TIME)),
                DF::quadNoSubject($schema->dateEnd, DF::literal($data->published_at, null, RDF::XSD_DATE_TIME)),
            ]);

            if (!empty($data->info)) {
                $meta->add(DF::quadNoSubject($schema->infor, DF::literal(trim($data->body), 'und')));
            }
        }

        return "$dir/acdh-schema.owl";
    }
}
