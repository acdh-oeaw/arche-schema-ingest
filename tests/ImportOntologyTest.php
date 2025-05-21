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

    const PROP_LABEL = 'https://vocabs.acdh.oeaw.ac.at/schema#hasTitle';

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

        $query = $pdo->prepare("
            SELECT count(*) 
            FROM identifiers i 
            WHERE 
                NOT EXISTS (SELECT 1 FROM metadata WHERE id = i.id AND property = ?)
                AND (ids LIKE ? OR ids LIKE ?)
        ");
        $query->execute([self::PROP_LABEL, RDF::NMSP_XSD . '%', RDF::NMSP_RDFS . '%']);
        $this->assertEquals(0, $query->fetchColumn());
    }

    public function testRemoveNotSetAttributes(): void {
        $pdo              = new PDO('pgsql: host=127.0.0.1 user=www-data');
        $queryMeta        = $pdo->prepare("
            SELECT property, value 
            FROM metadata 
            WHERE id = (SELECT id FROM identifiers WHERE ids = 'https://vocabs.acdh.oeaw.ac.at/schema#foo')
        ");
        $queryRecommended = $pdo->prepare("
            SELECT count(*)
            FROM 
                relations r 
                JOIN identifiers i1 USING (id) 
                JOIN identifiers i2 ON r.target_id = i2.id
            WHERE
                r.property = 'https://vocabs.acdh.oeaw.ac.at/schema#recommendedClass'
                AND i1.ids = 'https://vocabs.acdh.oeaw.ac.at/schema#foo'
                AND i2.ids = 'https://vocabs.acdh.oeaw.ac.at/schema#Collection'
        ");
        $_SERVER['argv']  = [
            'test',
            '--verbose',
            '--user', 'admin',
            '--pswd', 'pswd',
            '--concurrency', '6',
            '--ontologyFile', __DIR__ . '/withAttributes.owl',
            '--ontologyVersion', '99.0.0',
            '--ontologyUrl', 'https://github.com/acdh-oeaw/arche-schema',
            '--ontologyDate', '2099-12-31',
            '--ontologyInfo', 'Fake ontology version',
            'http://127.0.0.1/api'
        ];

        // with attributes
        require __DIR__ . '/../bin/arche-import-ontology';
        $queryMeta->execute([]);
        $meta = $queryMeta->fetchAll(PDO::FETCH_NUM);
        $meta = array_combine(array_map(fn($x) => $x[0], $meta), array_map(fn($x) => $x[1], $meta));
        $this->assertEquals(true, $meta['https://vocabs.acdh.oeaw.ac.at/schema#automatedFill'] ?? null);
        $this->assertEquals('https://default', $meta['https://vocabs.acdh.oeaw.ac.at/schema#defaultValue'] ?? null);
        $this->assertEquals('https://example', $meta['https://vocabs.acdh.oeaw.ac.at/schema#exampleValue'] ?? null);
        $this->assertEquals(12, $meta['https://vocabs.acdh.oeaw.ac.at/schema#ordering'] ?? null);
        $this->assertEquals('https://vocabs', $meta['https://vocabs.acdh.oeaw.ac.at/schema#vocabs'] ?? null);
        $queryRecommended->execute([]);
        $this->assertEquals(1, $queryRecommended->fetchColumn());

        // without attributes
        $_SERVER['argv'][9] = __DIR__ . '/withoutAttributes.owl';
        require __DIR__ . '/../bin/arche-import-ontology';
        $queryMeta->execute([]);
        $meta               = $queryMeta->fetchAll(PDO::FETCH_NUM);
        $meta               = array_combine(array_map(fn($x) => $x[0], $meta), array_map(fn($x) => $x[1], $meta));
        $this->assertNull($meta['https://vocabs.acdh.oeaw.ac.at/schema#automatedFill'] ?? null);
        $this->assertNull($meta['https://vocabs.acdh.oeaw.ac.at/schema#defaultValue'] ?? null);
        $this->assertNull($meta['https://vocabs.acdh.oeaw.ac.at/schema#exampleValue'] ?? null);
        $this->assertNull($meta['https://vocabs.acdh.oeaw.ac.at/schema#ordering'] ?? null);
        $this->assertNull($meta['https://vocabs.acdh.oeaw.ac.at/schema#vocabs'] ?? null);
        $queryRecommended->execute([]);
        $this->assertEquals(0, $queryRecommended->fetchColumn());
    }
}
