<?php

use Illuminate\Database\Eloquent\Model as Eloquent;
use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use League\CommonMark\CommonMarkConverter;

class Book extends Eloquent {

    const REPO = '../books';
    const MANUSCRIPT_DIR = 'manuscript';
    const RENDER_DIR = 'render';
    protected $database = 'zibaldone';
    protected $table = 'books';
    protected $connection = 'mysql';

    public function references()
    {
        return $this->hasMany('Reference')->orderBy('created_at', 'asc');
    }

    public function orderedFragments()
    {
        return $this->hasMany('Fragment')->orderBy('position', 'asc');
    }

    public function getBookPath()
    {
        return self::REPO . '/' . $this->dir ;
    }

    public function getManuscriptPath()
    {
        return self::REPO . '/' . $this->dir . '/' . self::MANUSCRIPT_DIR;
    }

    public function getRenderPath()
    {
        return self::REPO . '/' . $this->dir . '/' . self::RENDER_DIR;
    }

    public function getRenderFilename()
    {
        return $this->dir . '.html';
    }

    public function save()
    {

        $this->normalizeValues();

        if (!$this->checkValues()) {
            return false;
        }

        $filesystem = new Filesystem(new Adapter(self::REPO));

        // creates the MANUSCRIPT_DIR
        if (!$filesystem->createDir($this->dir . '/' . self::MANUSCRIPT_DIR)) {
            return false;
        }

        // adds some basic files
        $filesystem->put($this->dir . '/' . 'README.md', '');
        $filesystem->put($this->dir . '/' . 'license.md', '');

        // creates the RENDER_DIR
        if (!$filesystem->createDir($this->dir . '/' . self::RENDER_DIR)) {
            return false;
        }

        return parent::save();
    }

    protected function normalizeValues()
    {
        $this->title = trim($this->title);
        $this->dir = strtolower(preg_replace('/[^A-Za-z0-9\-_]/', '', str_replace(' ', '_', $this->title)));
    }

    protected function checkValues()
    {
        if ( strlen($this->title) < 3 || strlen($this->title) > 50) {
            return false;
        }
        return true;
    }

    public function delete()
    {
        foreach ($this->references as $reference) {
            $reference->delete();
        }

        //TODO delete the fragments
        // require_once('models/fragment.php');
        // foreach (Fragment::getFragmentsByBook($this->id, 'object') as $fragment) {
        //     $fragment->delete();
        // }

        $filesystem = new Filesystem(new Adapter(self::REPO));
        $filesystem->deleteDir($this->dir);

        return parent::delete();
    }

    public function update()
    {
        $old_name = $this->dir;

        $this->normalizeValues();

        if (!$this->checkValues()) {
            return false;
        }

        //TODO flysystem lacks a rename dir method
        //$filesystem = new Filesystem(new Adapter(self::REPO));
        //$filesystem->rename($old_name, $this->dir);

        rename(self::REPO . '/' . $old_name, self::REPO . '/' . $this->dir);

        return parent::save();
    }

    public function listReferences()
    {
        $references = array();
        foreach ($this->references as $reference) {
            $reference = $reference->find($reference->id);
            $references[] = $reference->toArray();
        }
        return $references;
    }


    public function listFragmentFiles()
    {
        $allowed_exts = array('txt', 'md');
        $files = array();
        $filesystem = new Filesystem(new Adapter($this->getManuscriptPath()));
        $contents = $filesystem->listContents();

        foreach ($contents as $item) {
            // Book.txt is the Leanpub specific file for index. It will not be treated as a fragment
            if ($item['path'] == 'Book.txt') {
                break;
            }
            if ($item['type'] == 'file' && in_array($item['extension'], $allowed_exts)) {
                $files[$item['path']] = $item;
            }
        }
        return $files;
    }

    /**
     * Makes sure that the db represents exactly what's on the filesystem
     * @return [type] [description]
     */
    public function syncDbWithFs()
    {
        // looks for missing records in the db
        foreach ($files = $this->listFragmentFiles() as $key => $file) {

            if (!Fragment::whereRaw('`full_filename`="' . $file['path'] . '"')->first()) {

                // not found -> adds the fragment
                $fragment = new Fragment();
                $fragment->book_id = $this->id;
                $fragment->type = 'local';
                $fragment->full_filename = $file['path'];
                $fragment->menu_label = $fragment->guessMenuLabel();
                // pushes the new local fragments at the bottom of the fragments list
                $fragment->position = count($files) + $key + 1;
                $fragment->save();
            }
        }

        // deletes dead records in the db
        Fragment::whereRaw('`full_filename` not in ("' . implode('","', array_keys($files)) . '")')->delete();
    }

    public function fragmentsToHtml()
    {
        $filesystem = new Filesystem(new Adapter($this->getManuscriptPath()));

        $html_fragments = array();

        foreach ($this->orderedFragments as $fragment) {

            if (!$filesystem->has($fragment->full_filename)) {
                break;
            }

            if (!$content = $filesystem->read($fragment->full_filename)) {
                break;
            }

            $item = array();
            $item['id'] = $fragment->id;
            $item['child'] = $fragment->child;
            $item['menu_label'] = $fragment->menu_label;
            // TODO the converter should be switched accordingly to the reference or fragment type
            $converter = new CommonMarkConverter();
            $item['content'] = $converter->convertToHtml($content);

            if ($fragment->reference_id) {
                $item['origin'] = Reference::find($fragment->reference_id)->html_url;
            }
            $html_fragments[] = $item;
        }

        return $html_fragments;

    }

    public function store($html)
    {
        $filesystem = new Filesystem(new Adapter($this->getRenderPath()));

        if ($filesystem->has($this->getRenderFilename())) {
            $filesystem->delete($this->getRenderFilename());
        }

        return $filesystem->put($this->getRenderFilename(), $html);
    }

    public function getRenderInfo()
    {
        $filesystem = new Filesystem(new Adapter($this->getRenderPath()));

        if ($filesystem->has($this->getRenderFilename())) {
            $return = array();
            $return['filepath'] = $this->getRenderPath() . '/' . $this->getRenderFilename();
            $unixTime = DateTime::createFromFormat('U', $filesystem->getTimestamp($this->getRenderFilename()));
            $return['created'] = $unixTime->format('D, Y-m-d H:i:s');
            return $return;
        }

        return false;
    }
}
