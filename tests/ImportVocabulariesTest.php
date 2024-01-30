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
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
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
class ImportVocabulariesTest extends \PHPUnit\Framework\TestCase {

    static public function setUpBeforeClass(): void {
        exec("docker exec -u www-data arche bash -c \"echo 'truncate resources cascade;' | psql\" 2>&1 > /dev/null");
    }

    static public function tearDownAfterClass(): void {
        if (file_exists('tmp.owl')) {
            unlink('tmp.owl');
        }
    }

    public function testSimple(): void {
        $vocabUrl  = 'https://vocabs.acdh.oeaw.ac.at/rest/v1/arche_category/data';
        $pdo       = new PDO('pgsql: host=127.0.0.1 user=www-data');
        $vocabResp = (new Client())->send(new Request('GET', $vocabUrl, ['Accept' => 'application/rdf+xml']));
        $ds        = new Dataset();
        $ds->add(RdfIoUtil::parse($vocabResp, new DF()));
        $expected  = $ds->listSubjects(new PT(RDF::RDF_TYPE, RDF::SKOS_CONCEPT))->getValues();
        sort($expected);

        $_SERVER['argv'] = [
            'test',
            '--verbose',
            '--user', 'admin',
            '--pswd', 'pswd',
            '--vocabularyLocation', $vocabUrl,
            '--concurrency', '6',
            '--force',
            'http://127.0.0.1/api',
        ];
        require __DIR__ . '/../bin/arche-import-vocabularies';

        $query  = $pdo->prepare("
            SELECT ids
            FROM metadata m JOIN identifiers i USING (id)
            WHERE
                m.property = ?
                AND m.value = ?
                AND ids LIKE ?
            ORDER BY 1
        ");
        $query->execute([RDF::RDF_TYPE, RDF::SKOS_CONCEPT, 'https://vocabs.acdh.oeaw.ac.at/archecategory%']);
        $actual = $query->fetchAll(PDO::FETCH_COLUMN);
        // two array_diffs for cleaner error messages
        $this->assertEquals([], array_diff($expected, $actual));
        $this->assertEquals([], array_diff($actual, $expected));
        
        // test merging on exact match
        $query = $pdo->prepare("
            SELECT count(*) 
            FROM metadata_view 
            WHERE id = (
                SELECT id 
                FROM identifiers i1 JOIN identifiers i2 USING (id) 
                WHERE i1.ids = ? AND i2.ids = ?
            )
        ");
        $query->execute(['http://purl.org/dc/dcmitype/MovingImage', 'https://vocabs.acdh.oeaw.ac.at/archecategory/audioVisual']);
        $this->assertGreaterThan(15, $query->fetchColumn());
    }

    public function testFull(): void {
        $tmp             = explode("\n", (string) file_get_contents(__DIR__ . '/../vendor/acdh-oeaw/arche-schema/acdh-schema.owl'));
        $tmp             = array_filter($tmp, fn($x) => !preg_match('`v1/iso639_3|v1/oefos`', $x));
        file_put_contents('tmp.owl', implode("\n", $tmp));
        $_SERVER['argv'] = [
            'test',
            '--verbose',
            '--user', 'admin',
            '--pswd', 'pswd',
            '--concurrency', '6',
            '--force',
            '--ontologyFile', 'tmp.owl',
            'http://127.0.0.1/api',
            '--allowedNmsp', RDF::NMSP_SKOS,
        ];
        require __DIR__ . '/../bin/arche-import-vocabularies';
        // as for now test just for no error
        $this->assertTrue(true);
    }
}
