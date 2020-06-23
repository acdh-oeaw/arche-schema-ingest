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
 * Description of Property
 *
 * @author zozlak
 */
class Property {

    static private $literalTypes = [
        RDF::XSD_BOOLEAN,
        RDF::XSD_DATE, RDF::XSD_TIME, RDF::XSD_DATE_TIME, RDF::XSD_DURATION,
        RDF::XSD_DECIMAL, RDF::XSD_INTEGER,
        RDF::XSD_NEGATIVE_INTEGER, RDF::XSD_POSITIVE_INTEGER,
        RDF::XSD_NON_NEGATIVE_INTEGER, RDF::XSD_NON_POSITIVE_INTEGER,
        RDF::XSD_STRING,
    ];

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
     * Checks if a given property definition is valid
     */
    public function check(bool $verbose): bool {
        $result = true;
        $range  = (string) $this->res->getResource(RDF::RDFS_RANGE);

        if (empty($range)) {
            echo $verbose ? $this->res->getUri() . " - has an empty range\n" : '';
            $result = false;
        } else {

            if (!empty($this->res->get($this->schema->ontology->vocabs)) && $range !== RDF::XSD_ANY_URI) {
                echo $verbose ? $this->res->getUri() . " - uses vocabulary but its range $range is not xsd:anyURI\n" : '';
                $result = false;
            }

            if (!empty($this->res->get($this->schema->ontology->langTag)) && $range !== RDF::XSD_STRING) {
                echo $verbose ? $this->res->getUri() . " - requires a language tag but its range $range is not xsd:string\n" : '';
                $result = false;
            }
            if (!empty($this->res->get($this->schema->ontology->langTag)) && !$this->res->isA(RDF::OWL_DATATYPE_PROPERTY)) {
                echo $verbose ? $this->res->getUri() . " - requires a language tag but it's not a DatatypeProperty\n" : '';
                $result = false;
            }

            if ($this->res->isA(RDF::OWL_DATATYPE_PROPERTY) && !in_array($range, self::$literalTypes)) {
                echo $verbose ? $this->res->getUri() . " - is a DatatypeProperty but its range $range doesn't indicate a literal value\n" : '';
                $result = false;
            }
            if ($this->res->isA(RDF::OWL_OBJECT_PROPERTY) && in_array($range, self::$literalTypes)) {
                echo $verbose ? $this->res->getUri() . " - is an ObjectProperty but its range $range indicates a literal value\n" : '';
                $result = false;
            }
        }
        
        return $result;
    }

}
