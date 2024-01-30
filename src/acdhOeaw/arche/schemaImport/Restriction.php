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

use rdfInterface\DatasetNodeInterface;
use rdfInterface\TermInterface;
use quickRdf\DataFactory as DF;
use rdfInterface\NamedNodeInterface;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use zozlak\RdfConstants as RDF;

/**
 * Class checking ontology restrictions consistency.
 *
 * @author zozlak
 */
class Restriction extends Entity {

    private NamedNodeInterface $id;

    public function __construct(DatasetNodeInterface $res, object $schema) {
        parent::__construct($res, $schema);
        $this->id = DF::namedNode($this->schema->namespaces->ontology . 'restriction-' . microtime(true));
    }

    /**
     * Checks if a given restriction is consistent with the rest of the ontology
     */
    public function check(bool $verbose): ?bool {
        $subClassOfProp = DF::namedNode(RDF::RDFS_SUB_CLASS_OF);
        $resUri         = (string) $this->res->getNode();
        $base           = $this->schema->namespaces->ontology;
        $ds             = $this->res->getDataset();
        $i              = null;

        // there must be at least one class connected with the restriction 
        // (which in owl terms means there must be at least one class inheriting from the restriction)
        $children = $ds->listSubjects(new PT($subClassOfProp, $this->res->getNode()));
        $children = iterator_to_array($children);
        if (count($children) === 0) {
            echo $verbose ? "$resUri - no classes inherit from the restriction\n" : '';
            return false;
        }

        // property for which the restriction is defined must exist and have both domain and range defined
        $onPropDs = null;
        $onProp   = $this->getObject(RDF::OWL_ON_PROPERTY);
        if ($onProp === null) {
            echo $verbose ? "$resUri - it lacks owl:onProperty\n" : '';
        } else {
            $onPropDs   = $ds->copy(new QT($onProp));
            $propDomain = $onPropDs->getObject(new PT(RDF::RDFS_DOMAIN));
            if ($propDomain === null) {
                echo $verbose ? "$resUri - property $onProp has no rdfs:domain\n" : '';
                return false;
            }
            $propRange = $onPropDs->getObject(new PT(RDF::RDFS_RANGE));
            if ($propRange === null) {
                echo $verbose ? "$resUri - property $onProp has no rdfs:range\n" : '';
                return false;
            }

            // classes inheriting from the restriction must match or inherit from restriction's property domain
            // violation example:
            //   A isSubclassOf R [A has restriction R]
            //   R onProperty P
            //   P domain B
            //   A is not subclassOf B
            foreach ($children as $i) {
                if (!$this->doesInherit($i, $propDomain)) {
                    echo $verbose ? "restriction for class $i and property $onProp - the class is not a subclass of property's domain ($propDomain)\n" : '';
                    return false;
                }
            }

            $simplify = $this->res->any(new PT(DF::namedNode(RDF::OWL_ON_CLASS))) || $this->res->any(new PT(DF::namedNode(RDF::OWL_ON_DATA_RANGE)));
            if ($simplify) {
                echo $verbose ? "restriction $resUri for class $i and property $onProp is a qualified one\n" : '';
                return false;
            }
        }


        $min       = (string) $this->getObject(RDF::OWL_MIN_CARDINALITY);
        $max       = (string) $this->getObject(RDF::OWL_MAX_CARDINALITY);
        $exact     = (string) $this->getObject(RDF::OWL_CARDINALITY);
        $default   = (string) $onPropDs?->getObject(new PT($base . 'defaultValue'));
        $automated = (string) $onPropDs?->getObject(new PT($base . 'automatedFill'));
        if (!empty($exact) && $exact !== '1') {
            echo $verbose ? "restriction $resUri for class $i and property $onProp has cardinality $exact while the only supported value is 1\n" : '';
            return false;
        }
        if (!empty($max) && $max !== '1') {
            echo $verbose ? "restriction $resUri for class $i and property $onProp has max cardinality $max while the only supported value is 1\n" : '';
            return false;
        }
        if (!empty($min) && (int) $min > 1) {
            echo $verbose ? "restriction $resUri for class $i and property $onProp has min cardinality $max while the maximum supported value is 1\n" : '';
            return false;
        }

        // fix class inheritance
        foreach ($children as $i) {
            $ds->delete(new PT($subClassOfProp, $this->res->getNode()));
            $ds->add(DF::quad($i, $subClassOfProp, DF::namedNode($this->id)));
        }

        return true;
    }

    public function getId(): NamedNodeInterface {
        return $this->id;
    }

    /**
     * Checks if $what inherits from $from
     */
    private function doesInherit(TermInterface $what, TermInterface $from): bool {
        if ($what->equals($from) || in_array((string) $from, [RDF::OWL_THING, RDF::RDFS_LITERAL])) {
            return true;
        }
        $ds   = $this->res->getDataset();
        $flag = false;
        foreach ($ds->listSubjects(new PT(DF::namedNode(RDF::RDFS_SUB_CLASS_OF), $from)) as $i) {
            $flag |= self::doesInherit($what, $i);
        }
        return (bool) $flag;
    }
}
