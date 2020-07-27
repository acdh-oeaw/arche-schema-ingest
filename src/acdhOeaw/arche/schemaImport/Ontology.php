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
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\acdhRepoLib\BinaryPayload;
use acdhOeaw\acdhRepoLib\Repo;
use acdhOeaw\acdhRepoLib\RepoResource;
use acdhOeaw\acdhRepoLib\SearchConfig;
use acdhOeaw\acdhRepoLib\SearchTerm;
use acdhOeaw\acdhRepoLib\exception\NotFound;
use zozlak\RdfConstants as RDF;

/**
 * Description of Ontology
 *
 * @author zozlak
 */
class Ontology {

    // restrictions go first as checkRestriction() can affect the whole graph
    static private $collections = [
        RDF::OWL_ANNOTATION_PROPERTY,
        RDF::OWL_RESTRICTION,
        RDF::OWL_CLASS,
        RDF::OWL_OBJECT_PROPERTY,
        RDF::OWL_DATATYPE_PROPERTY,
    ];

    /**
     *
     * @var \EasyRdf\Graph
     */
    private $ontology;

    /**
     *
     * @var \object
     */
    private $schema;

    /**
     * 
     * @param object $schema
     */
    public function __construct(object $schema) {
        $this->schema = $schema;
    }

    /**
     * 
     * @param string $filename
     * @return void
     */
    public function loadFile(string $filename): void {
        $this->ontology = new Graph();
        $this->ontology->parseFile($filename);
        $this->ontology->resource(RDF::OWL_THING)->addResource(RDF::RDF_TYPE, RDF::OWL_CLASS);
    }

    /**
     * 
     * @param Repo $repo
     * @return void
     */
    public function loadRepo(Repo $repo): void {
        $this->ontology          = new Graph();
        $searchTerm              = new SearchTerm($this->schema->parent, '', '=', SearchTerm::TYPE_RELATION);
        $searchCfg               = new SearchConfig();
        $searchCfg->metadataMode = RepoResource::META_RESOURCE;
        foreach (self::$collections as $i) {
            $searchTerm->value = $i;
            $children          = $repo->getResourcesBySearchTerms([$searchTerm], $searchCfg);
            foreach ($children as $j) {
                /* @var $j RepoResource */
                $j->getGraph()->copy([], '/^$/', '', $this->ontology);
            }
        }
    }

    /**
     * 
     * @param string $propNmsp
     * @return bool
     */
    public function check(string $propNmsp = ''): bool {
        $n = strlen($propNmsp);

        $result = true;
        foreach ($this->ontology->allOfType(RDF::OWL_RESTRICTION) as $i) {
            $restriction = new Restriction($i, $this->schema);
            $result      &= $restriction->check(true) ?? true;
        }
        foreach ($this->ontology->allOfType(RDF::OWL_DATATYPE_PROPERTY) as $i) {
            if (!empty($propNmsp) && substr($i->getUri(), 0, $n) === $propNmsp) {
                $property = new Property($i, $this->schema);
                $result   &= $property->check(true);
            }
        }
        foreach ($this->ontology->allOfType(RDF::OWL_OBJECT_PROPERTY) as $i) {
            if (!empty($propNmsp) && substr($i->getUri(), 0, $n) === $propNmsp) {
                $property = new Property($i, $this->schema);
                $result   &= $property->check(true);
            }
        }
        return $result;
    }

    /**
     * 
     * @param Repo $repo
     * @param bool $verbose
     * @return void
     */
    public function import(Repo $repo, bool $verbose = false): void {
        echo $verbose ? "### Creating top-level collections\n" : '';

        $collections = array_merge([$this->schema->namespaces->ontology . 'ontology'], self::$collections);
        foreach ($collections as $i => $id) {
            $this->createCollection($repo, $id);
        }

        $ids      = [];
        $imported = [];
        foreach ($collections as $type) {
            echo $verbose ? "### Importing $type\n" : '';
            foreach ($this->ontology->allOfType($type) as $i) {
                switch ($type) {
                    case RDF::OWL_RESTRICTION:
                        $entity = new Restriction($i, $this->schema);
                        break;
                    case RDF::OWL_OBJECT_PROPERTY:
                    case RDF::OWL_DATATYPE_PROPERTY:
                        $entity = new Property($i, $this->schema);
                        break;
                    case RDF::OWL_CLASS:
                        $entity = new RdfClass($i, $this->schema);
                        break;
                    default:
                        $entity = new Entity($i, $this->schema);
                        break;
                }
                $import = $entity->check($verbose); // the check method may alter the $i RDF resource
                $id     = $entity->getId();
                if (preg_match('/^_:genid[0-9]+$/', $id)) {
                    echo $verbose ? "Skipping an anonymous resource \n" . $i->dump('text') : '';
                    $import = false;
                }
                if (in_array($id, $ids)) {
                    echo $verbose ? "Skipping a duplicated resource \n" . $i->dump('text') : '';
                    $import = false;
                }
                $ids[] = $id;
                if ($import === true) {
                    $meta       = $this->sanitizeOwlObject($i, $id, $type);
                    $repoRes    = Util::updateOrCreate($repo, $id, $meta, $verbose);
                    $imported[] = $repoRes->getUri();
                }
            }
        }

        echo $verbose ? "### Removing obsolete resources...\n" : '';
        array_shift($collections);
        foreach ($collections as $id) {
            Util::removeObsoleteChildren($repo, $id, $this->schema->parent, $imported, $verbose);
        }
    }

    /**
     * 
     * @param Repo $repo
     * @param string $owlPath
     * @param bool $verbose
     * @return void
     * @throws Exception
     */
    public function importOwlFile(Repo $repo, string $owlPath, bool $verbose): void {
        $s = $this->schema;

        echo $verbose ? "###  Updating the owl binary\n" : '';

        // collection storing all ontology binaries
        $collId = $s->namespaces->id . 'acdh-schema';
        try {
            $coll = $repo->getResourceById($collId);
        } catch (NotFound $e) {
            $meta = (new Graph())->resource('.');
            $meta->addResource($s->id, $collId);
            $meta->addLiteral($s->label, new Literal('ACDH ontology binaries', 'en'));
            $coll = $repo->createResource($meta);
        }
        echo $verbose ? "    " . $coll->getUri() . "\n" : '';

        $curId = preg_replace('/#$/', '', $this->schema->namespaces->ontology);
        $old   = null;

        $newMeta = (new Graph())->resource('.');
        $newMeta->addResource($s->id, $curId . '/' . date('Y-m-d_H:m:s'));
        $newMeta->addLiteral($s->label, new Literal('ACDH schema owl file', 'en'));
        $newMeta->addResource($s->parent, $coll->getUri());
        $newMeta->addResource($s->accessRestriction, 'https://vocabs.acdh.oeaw.ac.at/archeaccessrestrictions/public');

        $binary = new BinaryPayload(null, $owlPath, 'application/rdf+xml');
        try {
            $old = $repo->getResourceById($curId);

            $hash = (string) $old->getGraph()->getLiteral($s->hash);
            if (!preg_match('/^(md5|sha1):/', $hash)) {
                throw new Exception("fixity hash $hash not implemented - update the script");
            }
            $md5  = 'md5:' . md5_file($owlPath);
            $sha1 = 'sha1:' . sha1_file($owlPath);
            if (!in_array($hash, [$md5, $sha1])) {
                echo $verbose ? "    uploading a new version\n" : '';
                $new     = $repo->createResource($newMeta, $binary);
                echo $verbose ? "      " . $new->getUri() . "\n" : '';
                $oldMeta = $old->getGraph();
                $oldMeta->deleteResource($s->id, $curId);
                $oldMeta->addResource($s->versioning->isPrevOf, $new->getUri());
                $old->setGraph($oldMeta);
                $old->updateMetadata(RepoResource::UPDATE_OVERWRITE); // we must loose the old identifier

                $newMeta->addResource($s->id, $curId);
                $new->setMetadata($newMeta);
                $new->updateMetadata();
            } else {
                echo $verbose ? "    owl binary up to date\n" : '';
            }
        } catch (NotFound $e) {
            echo $verbose ? "    no owl binary - creating\n" : '';
            $newMeta->addResource($s->id, $curId);
            $new = $repo->createResource($newMeta, $binary);
            echo $verbose ? "      " . $new->getUri() . "\n" : '';
        }
    }

    /**
     * 
     * @param Repo $repo
     * @param bool $verbose
     * @return void
     */
    public function importVocabularies(Repo $repo, bool $verbose): void {
        echo $verbose ? "###  Importing external vocabularies\n" : '';

        $vocabsProp = $this->schema->ontology->vocabs;
        foreach ($this->ontology->resourcesMatching($vocabsProp) as $res) {
            foreach ($res->all($vocabsProp) as $vocabularyUrl) {
                $vocabularyUrl = (string) $vocabularyUrl;
                echo $verbose ? "$vocabularyUrl\n" : '';
                $vocabulary    = new Vocabulary($this->schema);
                try {
                    $vocabulary->loadUrl($vocabularyUrl);
                } catch (RequestException $e) {
                    echo $verbose ? "    fetch error" . $e->getMessage() . "\n" : '';
                }
                $vocabulary->update($repo, $verbose);
            }
        }
    }

    /**
     * 
     * @param Repo $repo
     * @param type $id
     * @return void
     */
    private function createCollection(Repo $repo, $id): void {
        try {
            $res = $repo->getResourceById($id);
        } catch (NotFound $e) {
            $meta = (new Graph())->resource('.');
            $meta->addLiteral($this->schema->label, new Literal(preg_replace('|^.*[/#]|', '', $id), 'en'));
            $meta->addResource($this->schema->id, $id);
            $res  = $repo->createResource($meta);
        }
    }

    /**
     * Prepares an RDF resource representing an OWL object
     * (class/dataProperty/objectProperty/restriction) for repository import.
     * 
     * @param \EasyRdf\Resource $res an owl object to be imported/updated
     * @param string $id desired owl object identifier (may differ from $res URI
     * @param string $parentId collection in which a repository repository 
     *   resource should be created
     * @return string URL of a created/updated repository resource
     */
    function sanitizeOwlObject(Resource $res, string $id, string $parentId): Resource {
        $meta = (new Graph())->resource('.');
        foreach ($res->propertyUris() as $p) {
            foreach ($res->allLiterals($p) as $v) {
                if ($v->getValue() !== '') {
                    $meta->addLiteral($p, $v->getValue(), $v->getLang() ?? 'en');
                }
            }
            foreach ($res->allResources($p) as $v) {
                if (!$v->isBNode()) {
                    $meta->addResource($p, $v);
                }
            }
        }

        $meta->addResource($this->schema->id, $id);
        $meta->addResource($this->schema->parent, $parentId);

        if (null === $meta->getLiteral($this->schema->label)) {
            $meta->addLiteral($this->schema->label, preg_replace('|^.*[/#]|', '', $id), 'en');
        }

        return $meta;
    }

}
