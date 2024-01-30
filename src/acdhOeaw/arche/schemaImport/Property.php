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

use quickRdf\DataFactory as DF;
use termTemplates\PredicateTemplate as PT;
use termTemplates\LiteralTemplate as LT;
use zozlak\RdfConstants as RDF;

/**
 * Description of Property
 *
 * @author zozlak
 */
class Property extends Entity {

    /**
     * 
     * @var array<string>
     */
    static private array $literalTypes = [
        RDF::XSD_BOOLEAN,
        RDF::XSD_DATE, RDF::XSD_TIME, RDF::XSD_DATE_TIME, RDF::XSD_DURATION,
        RDF::XSD_DECIMAL, RDF::XSD_INTEGER,
        RDF::XSD_NEGATIVE_INTEGER, RDF::XSD_POSITIVE_INTEGER,
        RDF::XSD_NON_NEGATIVE_INTEGER, RDF::XSD_NON_POSITIVE_INTEGER,
        RDF::XSD_FLOAT, RDF::XSD_DOUBLE,
        RDF::XSD_STRING,
        RDF::XSD_ANY_URI,
    ];

    /**
     * Checks if a given property definition is valid
     */
    public function check(bool $verbose): ?bool {
        $base   = $this->schema->namespaces->ontology;
        $resUri = (string) $this->res->getNode();
        $result = true;

        $datatypeTmpl = new PT(DF::namedNode(RDF::RDF_TYPE), DF::namedNode(RDF::OWL_DATATYPE_PROPERTY));
        $objectTmpl   = new PT(DF::namedNode(RDF::RDF_TYPE), DF::namedNode(RDF::OWL_OBJECT_PROPERTY));
        $isDatatype   = $this->res->any($datatypeTmpl);
        $isObject     = $this->res->any($objectTmpl);

        $range = (string) $this->getObject(RDF::RDFS_RANGE);
        if (empty($range)) {
            echo $verbose ? "$resUri - has an empty range\n" : '';
            $result = false;
        } else {
            if (!empty($this->getObject($base . 'langTag')) && (string) $range !== RDF::XSD_STRING) {
                echo $verbose ? "$resUri - requires a language tag but its range $range is not xsd:string\n" : '';
                $result = false;
            }
            if ($this->res->any(new PT($base . 'langTag')) && !$isDatatype) {
                echo $verbose ? "$resUri - requires a language tag but it's not a DatatypeProperty\n" : '';
                $result = false;
            }
            if ($isDatatype && !in_array((string) $range, self::$literalTypes)) {
                echo $verbose ? "$resUri - is a DatatypeProperty but its range $range doesn't indicate a literal value\n" : '';
                $result = false;
            }

            if ($isObject && in_array((string) $range, self::$literalTypes)) {
                echo $verbose ? "$resUri - is an ObjectProperty but its range $range indicates a literal value\n" : '';
                $result = false;
            }
        }

        if ($isDatatype && $this->res->any(new PT($base . 'vocabs'))) {
            echo $verbose ? "$resUri - is a DatatypeProperty with a vocabulary\n" : '';
            $result = false;
        }

        if ($this->res->any(new PT($base . 'recommendedClass', new LT(null, LT::ANY)))) {
            echo $verbose ? "$resUri - has a recommended annotation with a literal value\n" : '';
            $result = false;
        }

        return $result;
    }
}
