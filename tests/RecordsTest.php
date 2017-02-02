<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Escuccim\RecordCollection\Models\Record;
use Faker\Generator;

class RecordsTest extends BrowserKitTest
{
    use DatabaseTransactions;
    /**
     * A basic test example.
     *
     * @return void
     */

    public function testSearch(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(30);

        // get a random record and hope it doesn't match more things than are displayed on the page
        $record = Record::inRandomOrder()->first();

        // test search  by artist
        $this->visit('/records')
            ->type($record->artist, 'searchTerm')
            ->press('Search')
            ->see($record->title)
            ->see($record->label);

        // search by title
        $this->visit('/records')
            ->type($record->title, 'searchTerm')
            ->press('Search')
            ->see($record->artist)
            ->see($record->label);

        // search by catalog number, make sure our record has a cat no
        $record = Record::whereNotNull('catalog_no')->where('catalog_no', '!=', '')->inRandomOrder()->first();

        $this->visit('/records')
            ->type($record->catalog_no, 'searchTerm')
            ->select('catalog_no', 'searchBy')
            ->press('Search')
            ->see($record->artist)
            ->see($record->title)
            ->see($record->label);

        // get labels that have more results than will fit on one page, if there aren't any skip this part
        $labels = DB::select('select label FROM ' . config('records.table_name') . ' GROUP By label HAVING Count(label) > 23');

        if(count($labels)) {
            // pick one at random
            $count = count($labels);
            $rand = rand(0, $count - 1);
            $label = $labels[$rand];

            // get first record for this label
            $firstRecord = Record::where('label', $label->label)->orderBy('artist', 'asc')->orderBy('title', 'asc')->first();
            $lastRecord = Record::where('label', $label->label)->orderBy('artist', 'desc')->orderBy('title', 'desc')->first();

            $this->visit('/records/search?searchTerm=' . $label->label . '&searchBy=label')
                ->see($firstRecord->artist)
                ->see($firstRecord->title)
                ->dontSee($lastRecord->title);

            // change the sort
            $firstRecord = Record::where('label', $label->label)->orderBy('catalog_no', 'asc')->orderBy('artist', 'asc')->orderBy('title', 'asc')->first();
            $lastRecord = Record::where('label', $label->label)->orderBy('catalog_no', 'desc')->orderBy('artist', 'desc')->orderBy('title', 'desc')->first();

            $this->visit('/records/search?searchTerm=' . $label->label . '&searchBy=label&sort=catalog_no')
                ->see($firstRecord->artist)
                ->see($firstRecord->title)
                ->see($firstRecord->catalog_no)
                ->dontSee($lastRecord->title);
        }
    }


    public function testRecordPaginationAndSort(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(60);

        // test pagination - get number of pages
        $resultsPerPage = 23;
        $count = Record::count();
        $numPages = ceil($count / $resultsPerPage);
        // pick a random page and figure out how many records to skip
        $page = rand(1, $numPages);
        $numToSkip = ($resultsPerPage * ($page - 1)) + 2;

        // see what data will appear on that page
        $record = Record::orderBy('label', 'asc')->orderBy('artist', 'asc')->orderBy('title', 'asc')->skip($numToSkip)->first();

        $this->visit('/records?page=' . $page)
            ->see($record->artist)
            ->see($record->title);

        // test sort by artist
        $record = Record::orderBy('artist', 'asc')->orderBy('label', 'asc')->orderBy('artist', 'asc')->orderBy('title', 'asc')->skip($numToSkip)->first();

        $this->visit('/records?sort=artist&page=' . $page)
            ->see($record->artist)
            ->see($record->title)
            ->see($record->label);

        // test sort by catalog no
        $record = Record::orderBy('catalog_no', 'asc')->orderBy('label', 'asc')->orderBy('artist', 'asc')->orderBy('title', 'asc')->skip($numToSkip)->first();

        $this->visit('/records?sort=catalog_no&page=' . $page)
            ->see($record->artist)
            ->see($record->title)
            ->see($record->label);
    }

    public function testPermissionsWithoutUser(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(10);

        // without a user
        $this->visit('/records')
            ->see('Search')
            ->see('Search By')
            ->see('Artist')
            ->dontSee('Add Record');

        // get a record
        $record = Record::inRandomOrder()->first();

        // check it's page
        $this->visit('/records/' . $record->id)
            ->see($record->artist)
            ->see($record->title)
            ->dontSee('Edit');
    }

    public function testPermissionsWithNonAdminUser(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(10);

        // with a user
        $user = factory(App\User::class)->create();

        // make sure admin links don't show up for non-admin
        $this->actingAs($user)
            ->visit('/records')
            ->see('Search')
            ->see('Search By')
            ->see('Artist')
            ->dontSee('Add Record');

        // get a record
        $record = Record::inRandomOrder()->first();

        $this->actingAs($user)
            ->visit('/records/' . $record->id)
            ->see('Record Info')
            ->see($record->artist)
            ->see($record->title)
            ->dontSee('Edit Record');

        // make sure that user can't access pages they shouldn't be able to
        $response = $this->call('GET', '/records/'. $record->id . '/edit');
        $this->assertEquals(404, $response->status());

        // make sure that user can't access pages they shouldn't be able to
        $response = $this->call('GET', '/records/create');
        $this->assertEquals(404, $response->status());

        // destroy user
        $user->destroy($user->id);
    }

    public function testPermissionsWithAdminUser(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(10);

        // with admin user
        $user = factory(App\User::class)->create();
        $user->type = 1;
        $user->save();

        // see that all proper links appear
        $this->actingAs($user)
            ->visit('/records')
            ->see('Search')
            ->see('Search By')
            ->see('Artist')
            ->see('Add Record')
            ->click('Add Record')
            ->assertResponseOk()
            ->see('New Record')
            ->see('Add Record');

        // pick a random record
        $record = Record::inRandomOrder()->first();

        $this->actingAs($user)
            ->visit('/records/' . $record->id)
            ->see('Record Info')
            ->see($record->artist)
            ->see($record->title)
            ->see($record->lable)
            ->see('Edit Record')
            ->click('Edit Record')
            ->see('Edit Record')
            ->see('Update Record')
            ->press('Update Record')
            ->see('Your record has been updated!');

        // destroy user
        $user->destroy($user->id);
    }

    public function testEditRecord(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(10);

        $admin = factory(App\User::class)->create();
        $admin->type = 1;

        // pick a random record
        $record = Record::inRandomOrder()->first();

        $this->actingAs($admin)
            ->visit('/records/' . $record->id . '/edit')
            ->assertResponseOk()
            ->type('Test Artist', 'artist')
            ->press('Update Record')
            ->see('Your record has been updated!')
            ->see('Test Artist');

        // destroy user
        $admin->destroy($admin->id);
    }

    public function testAddRecord(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(1);

        $admin = factory(App\User::class)->create();
        $admin->type = 1;

        $label = Record::inRandomOrder()->first()->label;

        $this->actingAs($admin)
            ->visit('/records/create')
            ->type('Test Artist', 'artist')
            ->type('Test Title', 'title')
            ->select($label, 'label')
            ->type('XXX-1234', 'catalog_no')
            ->press('Save')
            ->see('The record has been created');

        $this->seeInDatabase('records_new', [
            'title' => 'Test Title'
        ]);

        $admin->destroy($admin->id);
    }

    public function testAPI(){
        // put some data in the DB so we have something to test
        $data = $this->addSampleData(20);
        $admin = factory(App\User::class)->create();
        $admin->type = 1;
        $token = $admin->api_token = 'TEST_TOKEN_123';
        $admin->save();

//        $token = 'TEST_TOKEN_123';

        // make sure it doesn't work without a token
        $this->visit('/api/records')
            ->dontSee('results');

        // try it now with a token
        $this->visit('/api/records?api_token=' . $token)
            ->assertResponseOk()
            ->see('results');

        // do a search that should have no results, assert response is 404
        $response = $this->call('GET', '/api/records?api_token=' . $token . '&artist=borkborkborkbar');
        $this->assertEquals(404, $response->status());


        /** do a search that should have results by label **/
        $label = Record::select('label')->distinct()->inRandomOrder()->first();
        $records = Record::where('label', $label->label)->get();

        // check to see that the items listed on the page match the results in the DB
        $this->visit('/api/records?api_token=' . $token . '&searchTerm=' . $label->label)
            ->assertResponseOk()
            ->see($label->label)
            ->see(count($records));

        // do the search for label
        $this->visit('/api/records?api_token=' . $token . '&label=' . $label->label)
            ->assertResponseOk()
            ->see($label->label)
            ->see(count($records));


        /** do a search that should have results by artist **/
        $artist = Record::select('artist')->where('artist', 'NOT LIKE', '%&%')->distinct()->inRandomOrder()->first();
        $records = Record::where('artist', $artist->artist)->get();

        // check to see that the items listed on the page match the results in the DB
        $this->visit('/api/records?api_token=' . $token . '&searchTerm=' . $artist->artist)
            ->assertResponseOk()
            ->see(json_encode($artist->artist))
            ->see(count($records));

        // do the search for artist
        $this->visit('/api/records?api_token=' . $token . '&artist=' . $artist->artist)
            ->assertResponseOk()
            ->see(json_encode($artist->artist))
            ->see(count($records));


        /** do a search that should have results by title **/
        $title = Record::select('title')->distinct()->inRandomOrder()->first();
        $records = Record::where('title', $title->title)->get();

        // check to see that the items listed on the page match the results in the DB
        $this->visit('/api/records?api_token=' . $token . '&searchTerm=' . $title->title)
            ->assertResponseOk()
            ->see(json_encode($title->title))
            ->see(count($records));

        // do the search for title
        $this->visit('/api/records?api_token=' . $token . '&title=' . $title->title)
            ->assertResponseOk()
            ->see(json_encode($title->title))
            ->see(count($records));
    }

    private function addSampleData($count = 5){
        for($i = 1; $i < $count; $i++) {
            $faker = Faker\Factory::create();
            $artist = $faker->name;
            $label = $faker->company;
            $cat_no = $faker->isbn10;
            $title = $faker->streetName;

            $record = new Record();
            $record->artist = $artist;
            $record->title = $title;
            $record->label = $label;
            $record->catalog_no = $cat_no;
            $record->save();
        }

        return null;
    }
}
