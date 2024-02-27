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

use RuntimeException;
use rdfInterface\NamedNodeInterface;
use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdf\DatasetNode;
use termTemplates\PredicateTemplate as PT;
use termTemplates\LiteralTemplate;
use termTemplates\NamedNodeTemplate;
use termTemplates\AnyOfTemplate;
use quickRdfIo\Util as RdfUtil;
use GuzzleHttp\Promise\RejectedPromise;
use acdhOeaw\arche\lib\BinaryPayload;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\exception\RepoLibException;
use acdhOeaw\arche\lib\ingest\SkosVocabulary as SV;
use acdhOeaw\arche\lib\ingest\MetadataCollection as MC;
use zozlak\RdfConstants as RDF;

/**
 * Description of Ontology
 *
 * @author zozlak
 */
class Ontology {

    /**
     * restrictions go first as checkRestriction() can affect the whole graph
     * @var array<string>
     */
    static private array $collections = [
        RDF::OWL_ANNOTATION_PROPERTY,
        RDF::OWL_RESTRICTION,
        RDF::OWL_CLASS,
        RDF::OWL_OBJECT_PROPERTY,
        RDF::OWL_DATATYPE_PROPERTY,
    ];
    private Dataset $ontology;
    private Schema $schema;

    public function __construct(Schema $schema) {
        $this->schema = $schema;
    }

    /**
     * 
     * @param string $filename
     * @return void
     */
    public function loadFile(string $filename): void {
        $this->ontology = new Dataset();
        $this->ontology->add(RdfUtil::parse($filename, new DF(), 'application/rdf+xml'));
        $this->ontology->add(DF::quad(DF::namedNode(RDF::OWL_THING), DF::namedNode(RDF::RDF_TYPE), DF::namedNode(RDF::OWL_CLASS)));
    }

    /**
     * 
     * @param Repo $repo
     * @return void
     */
    public function loadRepo(Repo $repo): void {
        $this->ontology          = new Dataset();
        $searchTerm              = new SearchTerm($this->schema->parent, '', '=', SearchTerm::TYPE_RELATION);
        $searchCfg               = new SearchConfig();
        $searchCfg->metadataMode = RepoResource::META_RESOURCE;
        foreach (self::$collections as $i) {
            $searchTerm->value = $i;
            $children          = $repo->getResourcesBySearchTerms([$searchTerm], $searchCfg);
            foreach ($children as $j) {
                $this->ontology->add($j->getGraph());
            }
        }
    }

    public function check(): bool {
        $result = true;
        $tmpl   = new PT(DF::namedNode(RDF::RDF_TYPE));
        foreach ($this->ontology->listSubjects($tmpl->withObject(DF::namedNode(RDF::OWL_RESTRICTION))) as $i) {
            $i           = new DatasetNode($i);
            $restriction = new Restriction($i->withDataset($this->ontology), $this->schema);
            $result      &= $restriction->check(true) ?? true;
        }
        $tmpl = new AnyOfTemplate([
            DF::namedNode(RDF::OWL_DATATYPE_PROPERTY),
            DF::namedNode(RDF::OWL_OBJECT_PROPERTY),
        ]);
        foreach ($this->ontology->listSubjects(new PT(DF::namedNode(RDF::RDF_TYPE), $tmpl)) as $i) {
            $i        = new DatasetNode($i);
            $property = new Property($i->withDataset($this->ontology), $this->schema);
            $result   &= $property->check(true);
        }
        return (bool) $result;
    }

    public function import(Repo $repo, bool $verbose = false,
                           int $concurrency = 3): void {
        echo $verbose ? "### Creating top-level collections\n" : '';

        $topColl = $this->createCollection($repo, $this->schema->namespaces->ontology . 'ontology');
        foreach (self::$collections as $id) {
            $this->createCollection($repo, $id, $topColl);
        }

        $ids      = [];
        $toImport = new MC($repo, null);
        echo $verbose ? "### Preparing ontology for ingestion\n" : '';
        $tmpl     = new PT(DF::namedNode(RDF::RDF_TYPE));
        foreach (self::$collections as $type) {
            foreach ($this->ontology->listSubjects($tmpl->withObject(DF::namedNode($type))) as $i) {
                $i = new DatasetNode($i);
                $i = $i->withDataset($this->ontology);
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
                if ($import) {
                    $id = $entity->getId();
                    if (preg_match('/^_:genid[0-9]+$/', (string) $id)) {
                        echo $verbose ? "Skipping an anonymous resource \n$i" : '';
                        continue;
                    }
                    if (in_array($id, $ids)) {
                        echo $verbose ? "Skipping a duplicated resource \n$i" : '';
                        continue;
                    }
                    $ids[] = $id;
                    $toImport->add($this->sanitizeOwlObject($i, $id, DF::namedNode($type)));
                } else {
                    echo $verbose ? "Skipping " . $i->getNode() . " because of failed checks\n" : '';
                }
            }
        }

        echo $verbose ? "### Ingesting the ontology\n" : '';
        $debug     = MC::$debug;
        MC::$debug = $verbose;
        $imported  = $toImport->import('', MC::SKIP, MC::ERRMODE_FAIL, $concurrency, $concurrency);
        $imported  = array_map(fn($x) => $x instanceof RepoResource ? $x->getUri() : '', $imported);
        MC::$debug = $debug;

        echo $verbose ? "### Removing obsolete resources...\n" : '';
        foreach (self::$collections as $id) {
            $this->removeObsoleteChildren($repo, $id, $this->schema->parent, $imported, $verbose, $concurrency);
        }
    }

    /**
     * 
     * @param Repo $repo
     * @param string $owlPath
     * @param bool $verbose
     * @param DatasetNode|null $collectionMeta
     * @param DatasetNode|null $resourceMeta
     * @return bool if owl file has been uploaded
     * @throws RuntimeException
     */
    public function importOwlFile(Repo $repo, string $owlPath, bool $verbose,
                                  ?DatasetNode $collectionMeta = null,
                                  ?DatasetNode $resourceMeta = null): bool {
        $s = $this->schema;

        echo $verbose ? "###  Updating the owl binary\n" : '';

        // collection storing all ontology binaries
        $collId = DF::namedNode($s->namespaces->id . 'acdh-schema');
        try {
            /** @var RepoResource $coll */
            $coll = $repo->getResourceById($collId);
            if ($collectionMeta !== null) {
                $coll->setMetadata($collectionMeta);
                $coll->updateMetadata();
            }
        } catch (NotFound $e) {
            $meta = $collectionMeta ?? new DatasetNode($collId);
            $meta->add(DF::quad($collId, $s->id, $collId));
            if ($meta->none(new PT($s->label))) {
                $meta->add(DF::quad($collId, $s->label, DF::literal('ACDH ontology binaries', 'en')));
            }
            $coll = $repo->createResource($meta);
        }
        echo $verbose ? "    " . $coll->getUri() . "\n" : '';

        $curId = DF::namedNode(preg_replace('/#$/', '', $this->schema->namespaces->ontology));
        $old   = null;

        $newMeta = $resourceMeta ?? new DatasetNode($curId);
        $newMeta->add([
            DF::quad($curId, $s->id, DF::namedNode($curId . '/' . date('Y-m-d_H:m:s'))),
            DF::quad($curId, $s->parent, DF::namedNode($coll->getUri()))
        ]);
        if ($newMeta->none(new PT($s->label))) {
            $newMeta->add(DF::quad($curId, $s->label, DF::literal('ACDH schema owl file', 'en')));
        }

        $updated = true;
        $binary  = new BinaryPayload(null, $owlPath, 'application/rdf+xml');
        try {
            /** @var RepoResource $old */
            $old = $repo->getResourceById($curId);

            $hash = (string) $old->getGraph()->getObject(new PT($s->hash));
            if (!preg_match('/^(md5|sha1):/', $hash)) {
                throw new RuntimeException("fixity hash $hash not implemented - update the script");
            }
            $md5  = 'md5:' . md5_file($owlPath);
            $sha1 = 'sha1:' . sha1_file($owlPath);
            if (!in_array($hash, [$md5, $sha1])) {
                echo $verbose ? "    uploading a new version\n" : '';
                $new     = $repo->createResource($newMeta, $binary);
                echo $verbose ? "      " . $new->getUri() . "\n" : '';
                $oldMeta = $old->getGraph();
                $oldMeta->delete(new PT($s->id, $curId));
                $old->setGraph($oldMeta);
                $old->updateMetadata(RepoResource::UPDATE_OVERWRITE); // we must loose the old identifier

                $newMeta->add([
                    DF::quad($newMeta->getNode(), $s->id, $curId),
                    DF::quad($newMeta->getNode(), $s->isNewVersionOf, DF::namedNode($old->getUri())),
                ]);
                $new->setMetadata($newMeta);
                $new->updateMetadata();
            } else {
                echo $verbose ? "    owl binary up to date\n" : '';
                if ($resourceMeta !== null) {
                    echo $verbose ? "    updating owl binary metadata " . $old->getUri() . "\n" : '';
                    $old->setMetadata($newMeta);
                    $old->updateMetadata();
                }
                $updated = false;
            }
        } catch (NotFound $e) {
            echo $verbose ? "    no owl binary - creating\n" : '';
            $newMeta->add(DF::quadNoSubject($s->id, $curId));
            $new = $repo->createResource($newMeta, $binary);
            echo $verbose ? "      " . $new->getUri() . "\n" : '';
        }
        return $updated;
    }

    /**
     * Returns an array of vocabulary URIs used by the ontology
     * 
     * @return array<string>
     */
    public function getVocabularies(): array {
        $vocabsProp = $this->schema->ontology->vocabs;
        return $this->ontology->listObjects(new PT($vocabsProp))->getValues();
    }

    /**
     * 
     * @param Repo $repo
     * @param string $id
     * @return RepoResource
     */
    private function createCollection(Repo $repo, string $id,
                                      ?RepoResource $topColl = null): RepoResource {
        try {
            $res = $repo->getResourceById($id);
        } catch (NotFound $e) {
            $id   = DF::namedNode($id);
            $meta = new DatasetNode($id);
            $meta->add(DF::quad($id, $this->schema->label, DF::literal((string) preg_replace('|^.*[/#]|', '', $id), 'en')));
            $meta->add(DF::quad($id, $this->schema->id, $id));
            if (str_starts_with((string) $id, 'http://www.w3.org/2002/07/owl#')) {
                $desc = "A technical collection for storing internal representation of ontology objects of class $id.";
            } else {
                $desc = "A technical collection for storing internal representation of ontology objects.";
            }
            $meta->add(DF::quad($id, $this->schema->ontology->description, DF::literal($desc, 'en')));
            if ($topColl !== null) {
                $meta->add(DF::quad($id, $this->schema->parent, DF::namedNode($topColl->getUri())));
            }
            $res = $repo->createResource($meta);
        }
        return $res;
    }

    /**
     * Prepares an RDF resource representing an OWL object
     * (class/dataProperty/objectProperty/restriction) for repository import.
     * 
     * @param DatasetNode $res an owl object metadata to be sanitized
     * @param NamedNodeInterface $id desired owl object identifier (may differ 
     *   from $res node)
     * @param NamedNodeInterface $parentId collection in which a repository 
     *   resource should be created
     * @return DatasetNode created/updated repository resource metadata
     */
    private function sanitizeOwlObject(DatasetNode $res, NamedNodeInterface $id,
                                       NamedNodeInterface $parentId): DatasetNode {
        $meta = new DatasetNode($res->getNode());

        $meta->add($res->copy(new PT(null, new LiteralTemplate('', LiteralTemplate::NOT_EQUALS))));
        $meta->add($res->copy(new PT(null, new NamedNodeTemplate(null, NamedNodeTemplate::ANY))));
        $meta->add(DF::quadNoSubject($this->schema->id, $id));
        $meta->add(DF::quadNoSubject($this->schema->parent, $parentId));

        if ($meta->none(new PT($this->schema->label))) {
            $meta->add(DF::quadNoSubject($this->schema->label, DF::literal(preg_replace('|^.*[/#]|', '', $id), 'en')));
        }

        return $meta;
    }

    /**
     * 
     * @param Repo $repo
     * @param string $collectionId
     * @param string $parentProp
     * @param array<string> $imported
     * @param bool $verbose
     * @param int $concurrency
     * @return void
     */
    private function removeObsoleteChildren(Repo $repo, string $collectionId,
                                            string $parentProp, array $imported,
                                            bool $verbose, int $concurrency = 3): void {
        $searchTerm              = new SearchTerm($parentProp, $collectionId, '=', SearchTerm::TYPE_RELATION);
        $searchCfg               = new SearchConfig();
        $searchCfg->metadataMode = RepoResource::META_RESOURCE;
        $children                = $repo->getResourcesBySearchTerms([$searchTerm], $searchCfg);
        foreach ($children as $res) {
            $retries = $concurrency;
            /** @var RepoResource $res */
            $toDel   = [];
            if (!in_array($res->getUri(), $imported)) {
                $toDel[] = $res;
            }
            $f = function (RepoResource $res) use ($verbose) {
                echo $verbose ? "    " . $res->getUri() . "\n" : '';
                return $res->deleteAsync(true);
            };
            while ($retries > 0 && count($toDel) > 0) {
                $results = $repo->map($toDel, $f, $concurrency, Repo::REJECT_INCLUDE);
                $tmp     = [];
                foreach ($results as $n => $i) {
                    if ($i instanceof RejectedPromise) {
                        $tmp[] = $toDel[$n];
                    }
                }
                $toDel = $tmp;
                $retries--;
            }

            if (count($toDel) > 0) {
                throw new RepoLibException("Failed to remove all obsolete $collectionId children");
            }
        }
    }
}
