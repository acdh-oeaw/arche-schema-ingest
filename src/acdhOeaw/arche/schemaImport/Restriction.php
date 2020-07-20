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
use zozlak\RdfConstants as RDF;

/**
 * Class checking ontology restrictions consistency.
 *
 * @author zozlak
 */
class Restriction {

    /**
     *
     * @var EasyRdf\Resource
     */
    private $res;

    /**
     *
     * @var object
     */
    private $schema;

    public function __construct(Resource $res, object $schema) {
        $this->res    = $res;
        $this->schema = $schema;
    }

    /**
     * Checks if a given restriction is consistent with the rest of the ontology
     */
    public function check(bool $verbose): ?bool {
        // there must be at least one class connected with the restriction 
        // (which in owl terms means there must be at least one class inheriting from the restriction)
        $children = $this->res->getGraph()->resourcesMatching(RDF::RDFS_SUB_CLASS_OF, $this->res);
        if (count($children) === 0) {
            echo $verbose ? $this->res->getUri() . " - no classes inherit from the restriction\n" : '';
            return false;
        }

        // property for which the restriction is defined must exist and have both domain and range defined
        $prop = $this->res->getResource(RDF::OWL_ON_PROPERTY);
        if ($prop === null) {
            echo $verbose ? $this->res->getUri() . " - it lacks owl:onProperty\n" : '';
        }
        $propDomain = $prop->getResource(RDF::RDFS_DOMAIN);
        if ($propDomain === null) {
            echo $verbose ? $this->res->getUri() . " - property " . $prop->getUri() . " has no rdfs:domain\n" : '';
            return false;
        }
        $propRange = $prop->getResource(RDF::RDFS_RANGE);
        if ($propRange === null) {
            echo $verbose ? $this->res->getUri() . " - property " . $prop->getUri() . " has no rdfs:range\n" : '';
            return false;
        }

        // classes inheriting from the restriction must match or inherit from restriction's property domain
        // violation example:
        //   A isSubclassOf R [A has restriction R]
        //   R onProperty P
        //   P domain B
        //   A is not subclassOf B
        foreach ($children as $i) {
            if (!Util::doesInherit($i, $propDomain)) {
                echo $verbose ? "restriction for class " . $i->getUri() . " and property " . $prop->getUri() . " - the class is not a subclass of property's domain (".$propDomain->getUri().")\n" : '';
                return false;
            }
        }

        $simplify = count($this->res->allResources(RDF::OWL_ON_CLASS)) + count($this->res->allResources(RDF::OWL_ON_DATA_RANGE));
        if ($simplify) {
            echo $verbose ? "simplifying " . $this->res->getUri() . "\n" : '';
            $this->res->deleteResource(RDF::OWL_ON_CLASS);
            $this->res->deleteResource(RDF::OWL_ON_DATA_RANGE);
            $qCard = [RDF::OWL_QUALIFIED_CARDINALITY, RDF::OWL_MIN_QUALIFIED_CARDINALITY,
                RDF::OWL_MAX_QUALIFIED_CARDINALITY];
            foreach ($qCard as $srcProp) {
                $targetProp = str_replace('qualifiedC', 'c', str_replace('Qualified', '', $srcProp));
                foreach ($this->res->allLiterals($srcProp) as $j) {
                    $this->res->addLiteral($targetProp, $j->getValue());
                }
                $this->res->delete($srcProp);
            }
        }

        $id = $this->generateId();

        // fix class inheritance
        foreach ($children as $i) {
            $i->deleteResource(RDF::RDFS_SUB_CLASS_OF, $this->res);
            $i->addResource(RDF::RDFS_SUB_CLASS_OF, $id);
        }

        return true;
    }

    public function generateId(): string {
        return $this->schema->namespaces->ontology . 'restriction-' . microtime(true);
    }

}
