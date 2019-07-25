<?php

/**
 * WeEngine Document System
 *
 * (c) We7Team 2019 <https://www.w7.cc>
 *
 * This is not a free software
 * Using it under the license terms
 * visited https://www.w7.cc for more details
 */

namespace W7\App\Model\Logic;

use W7\App\Event\ChangeDocumentEvent;
use W7\App\Event\CreateDocumentEvent;
use W7\App\Model\Entity\Chapter;
use W7\App\Model\Entity\ChapterContent;
use W7\App\Model\Entity\User;
use W7\App\Model\Entity\UserAuthorization;

class ChapterLogic extends BaseLogic
{
	public function createChapter($data, $content)
	{
		$chapter = Chapter::create($data);
		ChangeDocumentEvent::instance()->attach('id',$chapter->id)->dispatch();
		return $chapter;
	}

	public function updateChapter($id, $data)
	{
		Chapter::where('id', $id)->update($data);
		ChangeDocumentEvent::instance()->attach('id',$id)->dispatch();
		return true;
	}

	public function publishOrCancel($id, $is_show)
	{
		$document = Chapter::find($id);
		if ($document) {
			$document->is_show = $is_show;
			$document->save();
			ChangeDocumentEvent::instance()->attach('id',$id)->dispatch();

			return true;
		}

		throw new \Exception('该文档不存在');
	}

	public function getDocuments($page, $size, $category, $allow_ids, $is_show, $keyword)
	{
		return Chapter::when($category, function ($query) use ($category) {
			return $query->where('category_id', $category);
		})
			->when($allow_ids, function ($query) use ($allow_ids) {
				return $query->whereIn('id', $allow_ids);
			})
			->where(function ($query) use ($keyword) {
				if ($keyword) {
					$user_ids = User::where('username', 'like', $keyword)->pluck('id')->toArray();
					$query->whereIn('creator_id', $user_ids)->orWhere('name', 'like', '%'.$keyword.'%');
				}
			})
			->when(null !== $is_show, function ($query) use ($is_show) {
				return $query->where('is_show', $is_show);
			})
			->orderBy('sort', 'desc')
			->paginate($size, null, null, $page);
	}

	public function getDocument($id)
	{
		if (icache()->get('document_'.$id)) {
			return $this->get('document_'.$id);
		}
		$document = Chapter::where('id', $id)->first();
		if (!$document) {
			throw new \Exception('该文档不存在！');
		}
		$description = ChapterContent::where('document_id', $id)->first();
		if ($description) {
			$document['content'] = $description['content'];
		} else {
			$document['content'] = '';
		}
		icache()->set('document_'.$id, $document,24*3600);

		return $document;
	}

	public function searchDocument($keyword)
	{
		$document_ids = ChapterContent::where('content', 'like', '%'.$keyword.'%')->pluck('document_id')->toArray();
		$documents = Chapter::whereIn('id', $document_ids)->where('is_show', 1)->get()->toArray();
		foreach ($documents as &$document) {
			$document['content'] = ChapterContent::find($document['id'])->content ?? '';
		}

		return $documents;
	}

	public function deleteChapter($id)
	{
		if (Chapter::where('parent_id',$id)->count() > 0) {
			throw new \Exception('该章节下有子章节，不可删除！');
		}
		Chapter::destroy($id);
		ChapterContent::where('document_id', $id)->delete();
		ChangeDocumentEvent::instance()->attach('id',$id)->dispatch();
	}
}
