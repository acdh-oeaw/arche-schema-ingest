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

use EasyRdf\Resource;
use GuzzleHttp\Exception\RequestException;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\SearchTerm;
use acdhOeaw\arche\lib\exception\NotFound;
use zozlak\RdfConstants as RDF;

/**
 * Description of Util
 *
 * @author zozlak
 */
class Util {

    /**
     * 
     * @param Repo $repo
     * @param string $collectionId
     * @param string $parentProp
     * @param array<string> $imported
     * @param bool $verbose
     * @return void
     */
    static public function removeObsoleteChildren(Repo $repo,
                                                  string $collectionId,
                                                  string $parentProp,
                                                  array $imported, bool $verbose): void {
        $searchTerm              = new SearchTerm($parentProp, $collectionId, '=', SearchTerm::TYPE_RELATION);
        $searchCfg               = new SearchConfig();
        $searchCfg->metadataMode = RepoResource::META_RESOURCE;
        $children                = $repo->getResourcesBySearchTerms([$searchTerm], $searchCfg);
        foreach ($children as $res) {
            if (!in_array($res->getUri(), $imported)) {
                echo $verbose ? "    " . $res->getUri() . "\n" : '';
                $res->delete(true);
            }
        }
    }

    static public function updateOrCreate(Repo $repo, string $id,
                                          Resource $meta, bool $verbose): RepoResource {
        try {
            $repoRes = $repo->getResourceById($id);
            echo $verbose ? "updating " . $id : '';
            $repoRes->setMetadata($meta);
            $repoRes->updateMetadata(RepoResource::UPDATE_OVERWRITE);
        } catch (NotFound $e) {
            echo $verbose ? "creating " . $id : '';
            $repoRes = $repo->createResource($meta);
        } catch (RequestException $e) {
            echo $verbose ? "\n" . $meta->getGraph()->serialise('turtle') . "\n" : '';
            throw $e;
        }
        echo $verbose ? ' as ' . $repoRes->getUri() . "\n" : '';
        return $repoRes;
    }

    /**
     * Checks if $what inherits from $from
     */
    static public function doesInherit(Resource $what, Resource $from): bool {
        if ($what === $from || in_array((string) $from, [RDF::OWL_THING, RDF::RDFS_LITERAL])) {
            return true;
        }
        $flag = false;
        foreach ($from->getGraph()->resourcesMatching(RDF::RDFS_SUB_CLASS_OF, $from) as $i) {
            $flag |= self::doesInherit($what, $i);
        }
        return (bool) $flag;
    }

}
