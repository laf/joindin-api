<?php

// @codingStandardsIgnoreStart
class Talk_commentsController extends ApiController
// @codingStandardsIgnoreEnd
{
    public function getComments($request, $db)
    {
        $comment_id = $this->getItemId($request);

        // verbosity
        $verbose = $this->getVerbosity($request);

        // pagination settings
        $start          = $this->getStart($request);
        $resultsperpage = $this->getResultsPerPage($request);

        $mapper = new TalkCommentMapper($db, $request);
        if ($comment_id) {
            $list = $mapper->getCommentById($comment_id, $verbose);
            if (false === $list) {
                throw new Exception('Comment not found', 404);
            }

            return $list;
        }

        return false;
    }

    public function getReported($request, $db)
    {
        $event_id = $this->getItemId($request);
        if (empty($event_id)) {
            throw new UnexpectedValueException("Event not found", 404);
        }

        $event_mapper   = new EventMapper($db, $request);
        $comment_mapper = new TalkCommentMapper($db, $request);

        if (! isset($request->user_id) || empty($request->user_id)) {
            throw new Exception("You must log in to do that", 401);
        }

        if ($event_mapper->thisUserHasAdminOn($event_id)) {
            $list = $comment_mapper->getReportedCommentsByEventId($event_id);
            return $list->getOutputView($request);
        } else {
            throw new Exception("You don't have permission to do that", 403);
        }
    }

    public function reportComment($request, $db)
    {
        // must be logged in to report a comment
        if (! isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to report a comment', 401);
        }

        $comment_mapper = new TalkCommentMapper($db, $request);

        $commentId   = $this->getItemId($request);
        $commentInfo = $comment_mapper->getCommentInfo($commentId);
        if (false === $commentInfo) {
            throw new Exception('Comment not found', 404);
        }

        $talkId  = $commentInfo['talk_id'];
        $eventId = $commentInfo['event_id'];

        $comment_mapper->userReportedComment($commentId, $request->user_id);

        // notify event admins
        $comment      = $comment_mapper->getCommentById($commentId, true, true);
        $event_mapper = new EventMapper($db, $request);
        $recipients   = $event_mapper->getHostsEmailAddresses($eventId);
        $event        = $event_mapper->getEventById($eventId, true, true);

        $emailService = new CommentReportedEmailService($this->config, $recipients, $comment, $event);
        $emailService->sendEmail();

        // send them to the comments collection
        $uri = $request->base . '/' . $request->version . '/talks/' . $talkId . "/comments";
        header("Location: " . $uri, true, 202);
        exit;
    }

    /**
     * Moderate a reported comment.
     *
     * This action is performed by a user that has administrative rights to the
     * event that this comment is for. The user provides a decision on the
     * report. That is, the user can approve the report which means that the
     * comment remains hidden from view or the user can deny the report which
     * means that the comment is viewable again.
     *
     * @param Request $request the request
     * @param PDO $db the database adapter
     */
    public function moderateReportedComment($request, $db)
    {
        // must be logged in
        if (! isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to moderate a comment', 401);
        }

        $comment_mapper = new TalkCommentMapper($db, $request);

        $commentId = $this->getItemId($request);
        $commentInfo = $comment_mapper->getCommentInfo($commentId);
        if (false === $commentInfo) {
            throw new Exception('Comment not found', 404);
        }

        $event_mapper = new EventMapper($db, $request);
        $event_id = $commentInfo['event_id'];
        if (false == $event_mapper->thisUserHasAdminOn($event_id)) {
            throw new Exception("You don't have permission to do that", 403);
        }

        $decision = $request->getParameter('decision');
        if (!in_array($decision, ['approved', 'denied'])) {
            throw new Exception('Unexpected decision', 400);
        }

        $comment_mapper->moderateReportedComment($decision, $commentId, $request->user_id);

        $talk_id  = $commentInfo['talk_id'];
        $uri = $request->base  . '/' . $request->version . '/talks/' . $talk_id . "/comments";
        header("Location: $uri", true, 204);
        exit;
    }

    public function updateComment($request, $db)
    {
        // must be logged in
        if (!isset($request->user_id) || empty($request->user_id)) {
            throw new Exception('You must log in to edit a comment', 401);
        }

        $new_comment_body = $request->getParameter('comment');
        if (empty($new_comment_body)) {
            throw new Exception('The field "comment" is required', 400);
        }

        $comment_id = $this->getItemId($request);
        $comment_mapper = new TalkCommentMapper($db, $request);
        $comment = $comment_mapper->getRawComment($comment_id);

        if (false === $comment) {
            throw new Exception('Comment not found', 404);
        }

        if ($comment['user_id'] != $request->user_id) {
            throw new Exception('You are not the comment author', 403);
        }

        $max_comment_edit_minutes = 15;
        if (isset($this->config['limits']['max_comment_edit_minutes'])) {
            $max_comment_edit_minutes = $this->config['limits']['max_comment_edit_minutes'];
        }

        if ($comment['date_made'] + ($max_comment_edit_minutes * 60) < time()) {
            throw new Exception('Cannot edit the comment after ' . $max_comment_edit_minutes . ' minutes', 400);
        }

        $updateSuccess = $comment_mapper->updateCommentBody($comment_id, $new_comment_body);
        if (false === $updateSuccess) {
            throw new Exception('Comment update failed', 500);
        }

        return $comment_mapper->getCommentById($comment_id);
    }
}
