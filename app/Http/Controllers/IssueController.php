<?php
/**
 * GitScrum v0.1.
 *
 * @author  Renato Marinho <renato.marinho@s2move.com>
 * @license http://opensource.org/licenses/GPL-3.0 GPLv3
 */

namespace GitScrum\Http\Controllers;

use Illuminate\Http\Request;
use GitScrum\Http\Requests\IssueRequest;
use GitScrum\Models\Sprint;
use GitScrum\Models\UserStory;
use GitScrum\Models\Issue;
use GitScrum\Models\Organization;
use GitScrum\Models\IssueType;
use GitScrum\Models\ConfigStatus;
use GitScrum\Models\ConfigIssueEffort;
use Carbon\Carbon;
use Auth;

class IssueController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index($slug)
    {
        if ($slug) {
            $sprint = Sprint::slug($slug)
                ->with('issues.user')
                ->with('issues.users')
                ->with('issues.commits')
                ->with('issues.statuses')
                ->with('issues.status')
                ->with('issues.comments')
                ->with('issues.attachments')
                ->with('issues.type')
                ->first();

            $issues = $sprint->issues;
        } else {
            $sprint = null;
            $issues = Auth::user()->issues;
        }

        $issues = $issues->sortBy('position')->groupBy('config_status_id');

        $configStatus = ConfigStatus::type('issue')->get();

        if (!is_null($sprint) && !count($sprint)) {
            return redirect()->route('sprints.index');
        }

        //get all stories from this product with an open issue
        //@TODO make sure stories are managable by user
        $userStoriesIds = UserStory::select(['user_stories.id', 'user_stories.config_priority_id'])
            ->where('user_stories.product_backlog_id', $sprint->product_backlog_id)
            ->join('issues', function ($join) {
                $join->on('user_stories.id', '=', 'issues.user_story_id')
                    ->where('issues.config_status_id', 1);
            })
            ->groupBy(['user_stories.id', 'user_stories.config_priority_id'])
            ->orderBy('user_stories.config_priority_id')
            ->orderBy('user_stories.id')
            ->get();
        $openUserStories = [];
        foreach ($userStoriesIds as $oneUserStoryId) {
            $openUserStory = UserStory::find($oneUserStoryId->id);
            $openUserStory->load(['issues' => function($query) {
                    $query->where('config_status_id',1)
                    ->whereNull('sprint_id');
            }, 'issues.type']);
            if ($openUserStory->issues->count() > 0) {
                $openUserStory->load('priority');
                $openUserStories[] = $openUserStory;
            }
        }

        return view('issues.index')
            ->with('sprint', $sprint)
            ->with('issues', $issues)
            ->with('configStatus', $configStatus)
            ->with('openUserStories', $openUserStories);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($slug_sprint = null, $slug_user_story = null, $parent_id = null)
    {
        $issue_types = IssueType::where('enabled', 1)
            ->orderby('position', 'ASC')
            ->get();

        $issue_efforts = ConfigIssueEffort::where('enabled', 1)
            ->orderby('position', 'ASC')
            ->get();

        $userStory = $productBacklogs = null;

        if ((is_null($slug_sprint) || !$slug_sprint) && $slug_user_story) {
            $userStory = UserStory::slug($slug_user_story)->first();
            $productBacklogs = Auth::user()->productBacklogs($userStory->product_backlog_id);
            $usersByOrganization = Organization::find($userStory->productBacklog->organization_id)->users;
        } elseif ($slug_sprint) {
            $usersByOrganization = Organization::find(Sprint::slug($slug_sprint)->first()
                ->productBacklog->organization_id)->users;
        } else {
            $issue = Issue::find($parent_id);
            $productBacklogs = $issue->product_backlog_id;
            $usersByOrganization = Organization::find($issue->productBacklog->organization_id)->users;
        }

        return view('issues.create')
            ->with('productBacklogs', $productBacklogs)
            ->with('userStory', $userStory)
            ->with('slug', $slug_sprint)
            ->with('parent_id', $parent_id)
            ->with('issue_types', $issue_types)
            ->with('issue_efforts', $issue_efforts)
            ->with('usersByOrganization', $usersByOrganization)
            ->with('action', 'Create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(IssueRequest $request)
    {
        $issue = Issue::create($request->all());

        if (is_array($request->members)) {
            $issue->users()->sync($request->members);
        }

        return redirect()->route('issues.show', ['slug' => $issue->slug])
            ->with('success', trans('Congratulations! The Issue has been created with successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        $issue = Issue::slug($slug)
            ->with('sprint')
            ->with('type')
            ->with('configEffort')
            ->with('labels')
            ->first();

        $usersByOrganization = Organization::find($issue->productBacklog->organization_id)->users;

        $configStatus = ConfigStatus::type('issue')->get();
        return view('issues.show')
            ->with('issue', $issue)
            ->with('usersByOrganization', $usersByOrganization)
            ->with('configStatus', $configStatus);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($slug)
    {
        $issue = Issue::slug($slug)->first();

        $issue_types = IssueType::where('enabled', 1)
            ->orderby('position', 'ASC')
            ->get();

        $issue_efforts = ConfigIssueEffort::where('enabled', 1)
            ->orderby('position', 'ASC')
            ->get();

        $usersByOrganization = Organization::find($issue->productBacklog->organization_id)->users;

        $productBacklogs = Auth::user()->productBacklogs($issue->productBacklog->id, false);

        return view('issues.edit')
            ->with('productBacklogs', $productBacklogs)
            ->with('userStory', $issue->userStory)
            ->with('slug', isset($issue->sprint->slug) ? $issue->sprint->slug : null)
            ->with('issue_types', $issue_types)
            ->with('issue_efforts', $issue_efforts)
            ->with('usersByOrganization', $usersByOrganization)
            ->with('issue', $issue)
            ->with('action', 'Edit');
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param int                      $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update(IssueRequest $request, $slug)
    {
        $issue = Issue::slug($slug)->first();

        $issue->update($request->all());

        if (is_array($request->members)) {
            $issue->users()->sync($request->members);
        }

        return back()
            ->with('success', trans('Congratulations! The Issue has been edited with successfully'));
    }

    public function statusUpdate(Request $request, $slug = null, int $status = 0)
    {

        if (!isset($request->status_id)) {
            $request->status_id = $status;
        }
        $status = ConfigStatus::find($request->status_id);

        if ($request->ajax()) {
            $position = 1;

            try {
                foreach (json_decode($request->json) as $id) {
                    $issue = Issue::find($id);
                    if (empty($issue->sprint_id) && !empty($request->sprint_id)) {
                        $issue->assignToSprint($request->sprint_id);
                    }
                    $updateSuccess = $issue->updateStatusAndPosition($request->status_id, $status, $position);
                    ++$position;
                }

                return response()->json([
                    'success' => $updateSuccess,
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                ]);
            }
        } else {
            $issue = Issue::slug($slug)->firstOrFail();
            $issue->updateStatusAndPosition($request->status_id, $status);

            return back()->with('success', trans('Updated successfully'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy($slug)
    {
        $issue = Issue::slug($slug)->firstOrFail();

        if (isset($issue->userStory)) {
            $redirect = redirect()->route('user_stories.show', ['slug' => $issue->userStory->slug]);
        } else {
            $redirect = redirect()->route('sprints.show', ['slug' => $issue->sprint->slug]);
        }

        $issue->delete();

        return $redirect;
    }

    public function removeFromSprint($slug)
    {
        $issue = Issue::slug($slug)->firstOrFail();
        $sprintSlug = $issue->sprint->slug;
        $issue->removeFromSprint();
        return redirect()->route('issues.index', ['slug' => $sprintSlug]);
    }
}
