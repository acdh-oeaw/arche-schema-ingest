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

namespace acdhOeaw\arche\schemaImport;

use EasyRdf\Graph;
use EasyRdf\Literal;
use EasyRdf\Resource;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\BinaryPayload;
use acdhOeaw\arche\lib\exception\NotFound;
use zozlak\RdfConstants as RDF;

/**
 * Description of Vocabulary
 *
 * @author zozlak
 */
class Vocabulary {

    /**
     *
     * @var string
     */
    private $url;

    /**
     *
     * @var object
     */
    private $schema;

    /**
     *
     * @var \EasyRdf\Graph
     */
    private $graph;

    public function __construct(object $schema) {
        $this->schema = $schema;
    }

    public function loadUrl(string $vocabularyUrl): void {
        $this->url   = $vocabularyUrl;
        $options     = [
            'verify'          => false,
            'http_errors'     => true,
            'allow_redirects' => true,
            'headers'         => ['Accept' => ['text/turtle, application/rdf+xml, application/n-triples']],
        ];
        $client      = new Client($options);
        $resp        = $client->send(new Request('GET', $this->url));
        $this->graph = new Graph();
        $this->graph->parse((string) $resp->getBody(), $resp->getHeader('Content-Type')[0] ?? null);
    }

    public function loadFile(string $file, string $format = null): void {
        $this->graph = new Graph();
        $this->graph->parseFile($file, $format);
        $this->url   = ($this->graph->allOfType(RDF::SKOS_CONCEPT_SCHEMA)[0])->getUri();
    }

    public function update(Repo $repo, bool $verbose): bool {
        $turtle     = $this->graph->serialise('text/turtle');
        $schemaMeta = $this->sanitizeSchemaMeta();

        try {
            $collRepoRes = $repo->getResourceById($this->url);
            echo $verbose ? "  " . $collRepoRes->getUri() . "\n" : '';
            $oldHash     = (string) $collRepoRes->getMetadata()->getLiteral($this->schema->hash);
            if ('sha1:' . sha1($turtle) === $oldHash) {
                echo $verbose ? "    Skipping the update - hashes match\n" : '';
                return false;
            }
        } catch (NotFound $e) {
            $collRepoRes = $repo->createResource($schemaMeta);
            echo $verbose ? "  " . $collRepoRes->getUri() . "\n" : '';
        }
        $payload = new BinaryPayload($turtle, 'vocabulary.ttl', 'text/turtle');
        $collRepoRes->updateContent($payload);

        $collMeta = $collRepoRes->getMetadata();
        $collMeta->merge($schemaMeta);
        $collRepoRes->setMetadata($collMeta);
        $collRepoRes->updateMetadata();

        $imported = [];
        foreach ($this->graph->allOfType(RDF::SKOS_CONCEPT) as $concept) {
            $concept    = new SkosConcept($concept);
            $concept->sanitize($this->schema, $this->url);
            echo $verbose ? "\t" : '';
            $repoRes    = Util::updateOrCreate($repo, $concept->getUri(), $concept->getMetadata(), $verbose);
            $imported[] = $repoRes->getUri();
        }

        echo $verbose ? "    Removing obsolete concepts...\n" : '';
        Util::removeObsoleteChildren($repo, $this->url, $this->schema->parent, $imported, $verbose);

        return true;
    }

    /**
     * Vocabulary top-level metadata quality is typically very poor and has to
     * be cleaned up.
     * 
     * @return \EasyRdf\Resource
     */
    private function sanitizeSchemaMeta(): Resource {
        /* @var $meta \EasyRdf\Resource */
        $meta = $this->graph->allOfType(RDF::SKOS_CONCEPT_SCHEMA)[0];

        // add ACDH identifiers
        $meta->addResource($this->schema->id, $this->url);
        $meta->addResource($this->schema->id, $meta->getUri());

        // avoid circular dependencies hasTopConcept<->inSchema
        $meta->deleteResource(RDF::SKOS_HAS_TOP_CONCEPT);

        // add label if needed
        if (null === $meta->getLiteral($this->schema->label)) {
            foreach ($meta->allLiterals('http://purl.org/dc/elements/1.1/title') as $i) {
                $meta->addLiteral($this->schema->label, $i);
            }
            if (null === $meta->getLiteral($this->schema->label)) {
                $meta->addLiteral($this->schema->label, new Literal($this->url, 'en'));
            }
        }

        $vocabsNmsp    = $this->schema->namespaces->ontology;
        $vocabsNmspLen = strlen($vocabsNmsp);
        $preserveProp  = [$this->schema->label, $this->schema->id];
        foreach ($meta->propertyUris() as $p) {
            if (substr($p, 0, $vocabsNmspLen) === $vocabsNmsp) {
                if (!in_array($p, $preserveProp)) {
                    // get rid of all ACDH properties other than label and id 
                    $meta->deleteResource($p);
                    $meta->delete($p);
                }
            } else {
                if (!in_array($p, [RDF::RDF_TYPE])) {
                    // cast all non-ACDH URIs to literals
                    foreach ($meta->allResources($p) as $r) {
                        $meta->delete($p, $r);
                        $meta->addLiteral($p, new Literal($r->getUri(), null, RDF::XSD_ANY_URI));
                    }
                }
            }
        }

        return $meta;
    }

}
