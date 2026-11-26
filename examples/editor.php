<?php

require __DIR__.'/bootstrap.php';
if (! $canWrite()) {
    http_response_code(403);
    exit('Bạn cần đăng nhập và có quyền biên tập bài.');
}
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="vi">
<meta charset="utf-8">
<title>Biên tập bài viết</title>
<form id="article-form">
    <p><label>Tiêu đề <input name="title"></label></p>
    <p><label>Nội dung <textarea name="body" rows="12" cols="80"></textarea></label></p>
    <?php echo $widget->render([
        'authorize_url' => '/authorize.php',
        'form' => '#article-form',
        'fields' => ['Title' => '[name=title]', 'Content' => '[name=body]'],
    ], $csrf, $credentials->read()); ?>
</form>
</html>
