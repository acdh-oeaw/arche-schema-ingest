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
use zozlak\RdfConstants as RDF;

/**
 * Description of Class
 *
 * @author zozlak
 */
class RdfClass extends Entity {

    public function check(bool $verbose): ?bool {
        $topLevel = true;
        $ds       = $this->res->getDataset();
        $tmpl     = new PT(DF::namedNode(RDF::RDF_TYPE), DF::namedNode(RDF::OWL_CLASS));
        foreach ($this->res->listObjects(new PT(DF::namedNode(RDF::RDFS_SUB_CLASS_OF))) as $parent) {
            $topLevel &= $ds->none($tmpl->withSubject($parent));
        }
        if ($topLevel && $this->res->getNode()->getValue() !== RDF::OWL_THING) {
            $this->res->add(DF::quadNoSubject(DF::namedNode(RDF::RDFS_SUB_CLASS_OF), DF::namedNode(RDF::OWL_THING)));
        }

        return true;
    }
}
