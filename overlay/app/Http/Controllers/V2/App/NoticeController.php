<?php

namespace App\Http\Controllers\V2\App;

use App\Models\Notice;
use Illuminate\Http\Request;

class NoticeController extends BaseController
{
    public function index(Request $request)
    {
        $page = max((int) $request->input('page', 1), 1);
        $pageSize = min(max((int) $request->input('page_size', 10), 1), 100);
        $popupOnly = (int) $request->input('popup_only', 0) === 1;

        $query = Notice::query()
            ->where('show', 1)
            ->orderBy('created_at', 'DESC');

        $total = $query->count();
        $items = $query->forPage($page, $pageSize)->get();
        if ($popupOnly) {
            $items = $items->filter(function ($item) {
                $tags = (array) ($item->tags ?? []);
                return in_array('popup', $tags, true);
            })->values();
            $total = $items->count();
        }

        return $this->success([
            'items' => $items->map(function ($item) {
                return $this->transformNotice($item);
            })->values(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
            ],
        ]);
    }

    public function show($id)
    {
        $notice = Notice::query()
            ->where('id', $id)
            ->where('show', 1)
            ->first();

        if (!$notice) {
            return $this->error('Notice not found', 40401, 404);
        }

        return $this->success($this->transformNotice($notice));
    }

    private function transformNotice(Notice $notice): array
    {
        $tags = is_array($notice->tags) ? $notice->tags : ((array) $notice->tags);
        return [
            'id' => $notice->id,
            'title' => $notice->title,
            'content' => $notice->content,
            'img_url' => $notice->img_url,
            'tags' => array_values(array_filter($tags)),
            'show' => (bool) $notice->show,
            'is_popup' => in_array('popup', $tags, true),
            'created_at' => $notice->created_at,
            'updated_at' => $notice->updated_at,
        ];
    }
}
