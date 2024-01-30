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
use rdfInterface\NamedNodeInterface;
use rdfInterface\TermInterface;
use termTemplates\PredicateTemplate;
use quickRdf\DataFactory;
use acdhOeaw\arche\lib\Schema;

/**
 * Description of Entity
 *
 * @author zozlak
 */
class Entity {

    protected DatasetNodeInterface $res;
    protected Schema $schema;

    public function __construct(DatasetNodeInterface $res, object $schema) {
        $this->res    = $res;
        $this->schema = $schema;
    }

    public function check(bool $verbose): ?bool {
        return true;
    }

    public function getId(): NamedNodeInterface {
        return $this->res->getNode();
    }

    /**
     * Helper for getting an object value for a property passed as a string.
     * 
     * @param string $property
     * @return TermInterface|null
     */
    public function getObject(string $property): TermInterface | null {
        return $this->res->getObject(new PredicateTemplate(DataFactory::namedNode($property)));
    }
}
