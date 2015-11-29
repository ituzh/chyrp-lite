<?php
    class Uploader extends Feathers implements Feather {
        public function __init() {
            $this->setField(array("attr" => "title",
                                  "type"=> "text",
                                  "label" => __("Title", "uploader"),
                                  "optional" => true,
                                  "preview" => "markup_title"));
            $this->setField(array("attr" => "uploads",
                                  "type" => "file",
                                  "multiple" => "true",
                                  "label" => __("Files", "uploader"),
                                  "note" => _f("(Max. file size: %s)", ini_get('upload_max_filesize'), "uploader")));
            $this->setField(array("attr" => "caption",
                                  "type" => "text_block",
                                  "label" => __("Caption", "uploader"),
                                  "preview" => "markup_text"));

            $this->setFilter("title", array("markup_title", "markup_post_title"));
            $this->setFilter("caption", array("markup_text", "markup_post_text"));

            $this->respondTo("delete_post", "delete_files");
            $this->respondTo("post","post");
            $this->respondTo("filter_post","filter_post");
            $this->respondTo("post_options", "add_option");
        }

        private function filenames_serialize($files) {
            $serialized = json_encode($files, JSON_UNESCAPED_SLASHES);

            if (json_last_error())
                error(__("Error"), _f("Failed to serialize files because of JSON error: <code>%s</code>", json_last_error_msg(), "uploader"));

            return $serialized;
        }

        private function filenames_unserialize($filenames) {
            $unserialized = json_decode($filenames, true);

            if (json_last_error() and DEBUG)
                error(__("Error"), _f("Failed to unserialize files because of JSON error: <code>%s</code>", json_last_error_msg(), "uploader"));

            return $unserialized;
        }

        public function submit() {
            if (isset($_FILES['uploads']) and upload_tester($_FILES['uploads'])) {
                $filenames = array();
                if (is_array($_FILES['uploads']['name']))
                    for($i=0; $i < count($_FILES['uploads']['name']); $i++)
                            $filenames[] = upload(array('name' => $_FILES['uploads']['name'][$i],
                                                        'type' => $_FILES['uploads']['type'][$i],
                                                        'tmp_name' => $_FILES['uploads']['tmp_name'][$i],
                                                        'error' => $_FILES['uploads']['error'][$i],
                                                        'size' => $_FILES['uploads']['size'][$i]));
                else
                    $filenames[] = upload($_FILES['uploads']);
            } else
                error(__("Error"), __("You did not select any files to upload.", "uploader"));

            if (!empty($_POST['option']['source']) and is_url($_POST['option']['source']))
                $_POST['option']['source'] = add_scheme($_POST['option']['source']);

            fallback($_POST['slug'], sanitize($_POST['title']));

            return Post::add(array("filenames" => self::filenames_serialize($filenames),
                                   "caption" => $_POST['caption'],
                                   "title" => $_POST['title']),
                             $_POST['slug'],
                             Post::check_url($_POST['slug']));
        }

        public function update($post) {
            if (isset($_FILES['uploads']) and upload_tester($_FILES['uploads'])) {
                $this->delete_files($post);
                $filenames = array();
                if (is_array($_FILES['uploads']['name']))
                    for($i=0; $i < count($_FILES['uploads']['name']); $i++)
                            $filenames[] = upload(array('name' => $_FILES['uploads']['name'][$i],
                                                        'type' => $_FILES['uploads']['type'][$i],
                                                        'tmp_name' => $_FILES['uploads']['tmp_name'][$i],
                                                        'error' => $_FILES['uploads']['error'][$i],
                                                        'size' => $_FILES['uploads']['size'][$i]));
                else
                    $filenames[] = upload($_FILES['uploads']);
            } else
                $filenames = $post->filenames;

            if (!empty($_POST['option']['source']) and is_url($_POST['option']['source']))
                $_POST['option']['source'] = add_scheme($_POST['option']['source']);

            $post->update(array("filenames" => self::filenames_serialize($filenames),
                                "caption" => $_POST['caption'],
                                "title" => $_POST['title']));
        }

        public function title($post) {
            return oneof($post->title,$post->title_from_excerpt());
        }

        public function excerpt($post) {
            return $post->caption;
        }

        public function feed_content($post) {
            return $post->caption;
        }

        public function delete_files($post) {
            if ($post->feather != "uploader")
                return;

            for ($i=0; $i < count($post->filenames); $i++) {
                unlink(MAIN_DIR.Config::current()->uploads_path.$post->filenames[$i]);
            }
        }

        public function post($post) {
            if ($post->feather != "uploader")
                return;

            $post->filenames = self::filenames_unserialize($post->filenames);
        }

        public function filter_post($post) {
            if ($post->feather != "uploader")
                return;

            $post->files = self::list_files($post->filenames);
        }

        private function list_files($filenames) {
            $list = array();
            for ($i=0; $i < count($filenames); $i++) {
                $list[$i]['name'] = $filenames[$i];
                $list[$i]['type'] = strtolower(pathinfo($filenames[$i], PATHINFO_EXTENSION));
            }
            return $list;
        }

        public function image_tag($filename, $max_width = 640, $max_height = null, $more_args = "quality=100", $sizes = "100vw") {
            $config = Config::current();

            # Source set for responsive images
            $srcset = array($config->chyrp_url.'/includes/thumb.php?file='.urlencode($filename).'&amp;max_width='.$max_width.'&amp;max_height='.$max_height.'&amp;'.$more_args.' 1x',
                            $config->chyrp_url.'/includes/thumb.php?file='.urlencode($filename).'&amp;max_width=960&amp;'.$more_args.' 960w',
                            $config->chyrp_url.'/includes/thumb.php?file='.urlencode($filename).'&amp;max_width=640&amp;'.$more_args.' 640w',
                            $config->chyrp_url.'/includes/thumb.php?file='.urlencode($filename).'&amp;max_width=320&amp;'.$more_args.' 320w');

            $tag = '<img srcset="'.implode(", ", $srcset).'" sizes="'.$sizes.'"';
            $tag.= ' src="'.$config->chyrp_url.'/includes/thumb.php?file='.urlencode($filename);
            $tag.= '&amp;max_width='.$max_width.'&amp;max_height='.$max_height.'&amp;'.$more_args.'"';
            $tag.= ' alt="'.$filename.'" class="image">';

            return $tag;
        }

        public function image_link($filename, $max_width = 640, $max_height = null, $more_args = "quality=100", $sizes = "100vw") {
            return '<a href="'.uploaded($filename).'" class="image_link">'.$this->image_tag($filename, $max_width, $max_height, $more_args, $sizes).'</a>';
        }

        public function add_option($options, $post = null) {
            if (isset($post) and $post->feather != "uploader")
                return;
            elseif (Route::current()->action == "write_post")
                if (!isset($_GET['feather']) and Config::current()->enabled_feathers[0] != "uploader" or
                    isset($_GET['feather']) and $_GET['feather'] != "uploader")
                    return;

            $options[] = array("attr" => "option[source]",
                               "label" => __("Source", "uploader"),
                               "type" => "text",
                               "value" => oneof(@$post->source, ""));

            return $options;
        }
    }
