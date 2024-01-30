<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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

namespace acdhOeaw\arche\schemaImport\tests;

use PDO;
use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use quickRdfIo\Util as RdfIoUtil;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use termTemplates\NamedNodeTemplate as NNT;
use zozlak\RdfConstants as RDF;

/**
 * Description of IndexerTest
 *
 * @author zozlak
 */
class ImportOntologyTest extends \PHPUnit\Framework\TestCase {

    static public function setUpBeforeClass(): void {
        exec("docker exec -u www-data arche bash -c \"echo 'truncate resources cascade;' | psql\" 2>&1 > /dev/null");
    }

    static public function tearDownAfterClass(): void {
    }

    public function testPackage(): void {
        $_SERVER['argv'] = [
            'test',
            '--verbose',
            '--user', 'admin',
            '--pswd', 'pswd',
            '--concurrency', '6',
            'http://127.0.0.1/api'
        ];
        require __DIR__ . '/../bin/arche-import-ontology';
        $this->assertTrue(true);
    }

    public function testFile(): void {
        $nmsp = 'https://vocabs.acdh.oeaw.ac.at/schema#';

        $_SERVER['argv'] = [
            'test',
            '--verbose',
            '--user', 'admin',
            '--pswd', 'pswd',
            '--concurrency', '6',
            '--ontologyFile', __DIR__ . '/../vendor/acdh-oeaw/arche-schema/acdh-schema.owl',
            '--ontologyVersion', '99.0.0',
            '--ontologyUrl', 'https://github.com/acdh-oeaw/arche-schema',
            '--ontologyDate', '2099-12-31',
            '--ontologyInfo', 'Fake ontology version',
            'http://127.0.0.1/api'
        ];
        require __DIR__ . '/../bin/arche-import-ontology';

        $ds = new Dataset();
        $ds->add(RdfIoUtil::parse(__DIR__ . '/../vendor/acdh-oeaw/arche-schema/acdh-schema.owl', new DF(), 'application/rdf+xml'));

        $pdo       = new PDO('pgsql: host=127.0.0.1 user=www-data');
        $query     = $pdo->prepare("
            SELECT ids
            FROM metadata m JOIN identifiers i USING (id)
            WHERE
                m.property = ?
                AND m.value = ?
                AND ids LIKE ?
            ORDER BY 1
        ");
        $classTmpl = new QT(new NNT($nmsp, NNT::STARTS), DF::namedNode(RDF::RDF_TYPE));
        $classes   = [
            RDF::OWL_ANNOTATION_PROPERTY,
            RDF::OWL_CLASS,
            RDF::OWL_DATATYPE_PROPERTY,
            RDF::OWL_OBJECT_PROPERTY
        ];
        foreach ($classes as $class) {
            $expected = $ds->listSubjects($classTmpl->withObject(DF::namedNode($class)))->getValues();
            sort($expected);

            $query->execute([RDF::RDF_TYPE, $class, "$nmsp%"]);
            $actual = $query->fetchAll(PDO::FETCH_COLUMN);

            // two array_diffs for cleaner error messages
            $this->assertEquals([], array_diff($expected, $actual), $class);
            $this->assertEquals([], array_diff($actual, $expected), $class);
        }

        $class    = RDF::OWL_RESTRICTION;
        $tmpl     = new PT(DF::namedNode(RDF::RDF_TYPE), DF::namedNode($class));
        $expected = $ds->copy($tmpl)->count();
        $query->execute([RDF::RDF_TYPE, $class, "$nmsp%"]);
        $actual   = count($query->fetchAll(PDO::FETCH_COLUMN));
        $this->assertEquals($expected, $actual, $class);
    }
}
