<?php

namespace App\Http\Controllers\Api\Pictures;

use App\Models\Fan;
use App\Models\Picture;
use App\Services\Qiniu;
use App\Services\Token;
use App\Models\FanShare;
use App\Models\PictureTag;
use App\Models\LikePicture;
use Illuminate\Http\Request;
use App\Models\CollectPicture;
use App\Models\DownloadPicture;
use App\Http\Controllers\Controller;
use App\Http\Requests\PictureRequest;

class PictureController extends Controller
{
    public function index() 
    {
        $pic_id = request('id');
        $tag_id = request('tag_id');
        $title = request('title');
        $author = request('author');
        // $collectOrder = request('collectOrder') ? 'asc' : 'desc';
        // $likeOrder = request('likeOrder') ? 'asc' : 'desc';
        $fan_id = request('fan_id') ?? Token::getUid();
        $picture_ids = null;
        if(isset($tag_id)) {
            $picture_ids = PictureTag::where('tag_id',$tag_id)->get()->pluck('picture_id');
        }
        $pictures = Picture::with(['tags'])->when($pic_id > 0, function($query) use ($pic_id) {
            return $query->where('pic_id', $pic_id);
        })->when($picture_ids, function($query) use ($picture_ids) {
            return $query->whereIn('id', $picture_ids);
        })->when($title, function($query) use ($title) {
            return $query->where('title', 'like', '%'.$title.'%');
        })->when($author, function($query) use ($author) {
            return $query->where('author', 'like', '%'.$author.'%');
        })->withCount(['likeFans', 'collectFans', 'downloadFans'])->orderBy('created_at', 'desc')->paginate(30); 
        
        return response()->json(['status' => 'success', 'data' => $pictures]);
    }

    public function show(Picture $picture)
    {     
        $fan_id = request('fan_id') ?? Token::getUid();
        
        $picture = $picture->where('id',$picture->id)->with(['tags' => function ($query){
            $query->select('tags.id', 'tags.name');
        }])->withCount(['likeFans', 'collectFans'])->first();    
        $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;
        $picture->like = $picture->isLike($fan_id) ? 1 : 0;
        $picture->download = $picture->isDownload($fan_id) ? 1 : 0;
        $status = $picture ? 'success' : 'error';
        return response()->json(['status' => $status, 'data' => $picture]);   
    }

    public function store(PictureRequest $request) 
    {
        $tags = $request->tags;

        $picture = Picture::create($request->picture);

        $picture_id = $picture->id;

        if($picture) {

            if($tags) {
                foreach($tags as $tag) {
                    PictureTag::create(['tag_id' => $tag, 'picture_id' => $picture_id]);
                }
            }

            return response()->json(['status' => 'success', 'msg' => '新增成功!']);               
        }

        return response()->json(['status' => 'error', 'msg' => '新增失败！']);               
    }


    public function update(PictureRequest $request, Picture $picture) 
    {
        $tags = $request->tags;

        $picture_id = $picture->id;

        if($picture->update($request->picture)){
            
            if($tags) {
                PictureTag::where('picture_id', $picture_id)->delete();

                foreach($tags as $tag) {
                    PictureTag::create(['tag_id' => $tag, 'picture_id' => $picture->id]);
                }
            }

            return response()->json(['status' => 'success', 'msg' => '更新成功！']);                  
        }

        return response()->json(['status' => 'error', 'msg' => '更新失败！']);               
    }


    public function destroy(Picture $picture)
    {
        // TODO:判断删除权限
        $url = $picture->url;
        if($picture->delete()) {

            PictureTag::where('picture_id',$picture->id)->delete();

            Qiniu::delete($url);
            
            return response()->json(['status' => 'success', 'msg' => '删除成功！']);   
        }

        return response()->json(['status' => 'error', 'msg' => '删除失败！']);
    }

    public function collect(Picture $picture) 
    {
        $param = [
            'fan_id' => request('fan_id') ?? Token::getUid(),
            'picture_id' => $picture->id
        ];

        if(CollectPicture::firstOrCreate($param)) {
            $picture->increment('hot', 5);            
            return response()->json(['status' => 'success', 'msg' => '收藏成功！']);  
        }

        return response()->json(['status' => 'error', 'msg' => '收藏失败！']);  
    }

    public function uncollect(Picture $picture) 
    {
        $fan_id = request('fan_id') ?? Token::getUid();
        if($picture->collect($fan_id)->delete()) {
            $picture->decrement('hot', 5);                        
            return response()->json(['status' => 'success', 'msg' => '取消成功！']);  
        }

        return response()->json(['status' => 'error', 'msg' => '取消失败！']);  
    }

    public function like(Picture $picture) 
    {
        $param = [
            'fan_id' => request('fan_id') ?? Token::getUid(),
            'picture_id' => $picture->id
        ];

        if(LikePicture::firstOrCreate($param)) {
            //更新热度
            $picture->increment('hot', 2);
            return response()->json(['status' => 'success', 'msg' => '点赞成功！']);  
        }

        return response()->json(['status' => 'error', 'msg' => '点赞失败！']);  
    }

    public function unlike(Picture $picture) 
    {
        $fan_id = request('fan_id') ?? Token::getUid();        
        if($picture->like($fan_id)->delete()) {
            $picture->decrement('hot', 2);            
            return response()->json(['status' => 'success', 'msg' => '取消成功！']);  
        }

        return response()->json(['status' => 'error', 'msg' => '取消失败！']);  
    }

    public function appList()
    {
        $fan_id = request('fan_id') ?? Token::getUid();                

        $pictures = Picture::with(['tags'])->withCount(['likeFans', 'collectFans'])->where('hidden', 0)->orderBy('created_at', 'desc')->paginate(30); 

        foreach($pictures as &$picture) {
            $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;
        }
        
        return response()->json(['status' => 'success', 'data' => $pictures]);
    }

    public function appRandomList()
    {
        $fan_id = request('fan_id') ?? Token::getUid();        
        $random_picture_ids = request('random_picture_ids') ?? [];   
        $limit = 20;

        $pictures = Picture::when(count($random_picture_ids) > 0, function($query) use ($random_picture_ids){
            return $query->whereNotIn('id', $random_picture_ids);
        })->where('hidden', 0)->with(['tags'])->withCount(['likeFans', 'collectFans'])->inRandomOrder()->limit($limit)->get(); 

        foreach($pictures as &$picture) {
            $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;
        }

        $picture_ids = $pictures->pluck('id')->toArray();

        $random_picture_ids = array_merge($random_picture_ids, $picture_ids);
        
        return response()->json(['status' => 'success', 'data' => $pictures,'random_picture_ids'=>$random_picture_ids]);
    }


    public function appShow(Picture $picture)
    {
        $fan_id = request('fan_id') ?? Token::getUid();

        $picture = $picture->where('id', $picture->id)->where('hidden', 0)->with(['tags' => function ($query){
            $query->select('tags.id', 'tags.name');
        }])->withCount(['likeFans', 'collectFans'])->first();

        $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;  //是否收藏
        $picture->like = $picture->isLike($fan_id) ? 1 : 0;   //是否点赞
        $picture->download = $picture->isDownload($fan_id) ? 1 : 0; //是否下载
        
        $picture->increment('hot', 1);  //增加一个热度           
        $picture->increment('click', 1);  //增加一个点击      
        
        //相关推荐
        $recommends = PictureTag::getRecommends($picture->id);
        $recommend_ids = $recommends->pluck('id');
        $status = $picture ? 'success' : 'error';
        return response()->json(['status' => $status, 'data' => $picture, 'recommends' => $recommends, 'recommend_ids' => $recommend_ids]);   
    }

    public function appShowByIds(Picture $picture)
    {
        $fan_id = request('fan_id') ?? Token::getUid();

        $recommend_ids = request('recommend_ids');

        $picture = $picture->where('id', $picture->id)->where('hidden', 0)->with(['tags' => function ($query){
            $query->select('tags.id', 'tags.name');
        }])->withCount(['likeFans', 'collectFans'])->first();

        $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;  //是否收藏
        $picture->like = $picture->isLike($fan_id) ? 1 : 0;   //是否点赞
        $picture->download = $picture->isDownload($fan_id) ? 1 : 0; //是否下载
        
        //相关推荐
        $recommends = PictureTag::getRecommendsByIds($recommend_ids);

        $status = $picture ? 'success' : 'error';
        return response()->json(['status' => $status, 'data' => $picture, 'recommends' => $recommends]);   
    }

    public function getListByTags()
    {
        $fan_id = request('fan_id') ?? Token::getUid();                
        $limit = 20;
        $tag_id = request('tag_id');
        $picture_ids = null;
        if(isset($tag_id)) {
            $picture_ids = PictureTag::where('tag_id',$tag_id)->get()->pluck('picture_id');
        }

        $pictures = Picture::with(['tags'])->where('hidden', 0)->when($picture_ids, function($query) use ($picture_ids) {
            return $query->whereIn('id', $picture_ids);
        })->withCount(['likeFans', 'collectFans'])->paginate($limit); 

        foreach($pictures as &$picture) {
            $picture->collect = $picture->isCollect($fan_id) ? 1 : 0;
        }
        
        return response()->json(['status' => 'success', 'data' => $pictures]);
    }

    public function rank()
    {             
        $keyword = request('keyword');
        $pictures = Picture::with(['tags'])->where('hidden', 0)->withCount(['likeFans', 'collectFans'])->when($keyword == 'collect', function($query) {
            return $query->orderBy('collect_fans_count', 'desc');
        })->when($keyword == 'like', function($query) {
            return $query->orderBy('like_fans_count', 'desc');
        })->when($keyword == 'hot', function($query){
            return $query->orderBy('hot', 'desc');
        })->paginate(20);

        return response()->json(['status' => 'success', 'data' => $pictures]);
        
    }

    public function addHot(Picture $picture)
    {
        $picture->increment('hot', 1);  //增加一个热度 
        $picture->increment('click', 1);  //增加一个点击 
        return response()->json(['status' => 'success']);
    }

    public function changeHidden(Picture $picture) 
    {

        if($picture->hidden == 0) {
            $picture->hidden = 1;
        } else {
            $picture->hidden = 0;
        }

        if($picture->save()) {
            return response()->json(['status' => 'success']);
        }

        return response()->json(['status' => 'error']);        

    }

    public function hiddenChangeAll() 
    {

        $hidden = request('hidden');

        Picture::update(['hidden' => $hidden]);

        return response()->json(['status' => 'success']);        

    }

    public function changeStatus() 
    {
        
        if(Picture::where('status',1)->update(['hidden' => request()->hidden])) {
            return response()->json(['status' => 'success']);
        }

        return response()->json(['status' => 'error']); 
        
    }

    public function download(Picture $picture)
    {
        $fan_id = request('fan_id') ?? Token::getUid();     
        $type = request('type');  
        $fan = Fan::find($fan_id);
        DownloadPicture::create(['fan_id' => $fan_id, 'picture_id' => $picture->id]);
        $flag = false;
        if($type == 0) {
            if($fan->point >= $picture->point) {
                $fan->decrement('point', $picture->point); 
                $flag = true;
            }
        } else {
            $date = date('Y-m-d', time());
            $share_count = FanShare::where('fan_id', $fan_id)->whereDate('created_at', $date)->count();
            if($share_count < 5) {
                FanShare::create(['fan_id', $fan_id]);  
                $flag = true;                        
            }
        }
    
        return response()->json(['status' => 'success', 'flag' => $flag]);         
    }
}
