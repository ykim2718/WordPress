// Y, 2025.12.6
jQuery(document).ready(function($) {
    // 'TRASH' 버튼 클릭 이벤트를 감지
    $('.comment-trash-btn').on('click', function(e) {
        e.preventDefault(); // 기본 링크 동작(페이지 이동/새로고침) 방지

        if (!confirm('정말로 이 댓글을 삭제하시겠습니까?')) {
            return; // 사용자가 취소하면 종료
        }

        var $button = $(this);
        var commentId = $button.data('comment-id');
        var nonce = $button.data('nonce');
        
        // 버튼을 비활성화하여 중복 클릭 방지
        $button.prop('disabled', true).text('삭제 중...'); 

        // AJAX 요청 설정
        $.ajax({
            url: my_ajax_object.ajax_url, // functions.php에서 전달받은 AJAX URL
            type: 'POST',
            data: {
                action: 'delete_comment_ajax', // wp_ajax_delete_comment_ajax의 'delete_comment_ajax' 부분
                comment_id: commentId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // 삭제 성공 시
                    alert('댓글이 성공적으로 휴지통으로 이동되었습니다.');
                    
                    // 프런트엔드에서 댓글 요소 제거 (DOM 조작)
                    // 삭제된 댓글의 부모 요소(일반적으로 li.comment 또는 article.comment)를 찾아서 숨기거나 제거
                    $button.closest('li.comment, article.comment').fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    // 삭제 실패 시
                    alert('댓글 삭제에 실패했습니다: ' + response.data);
                    $button.prop('disabled', false).text('TRASH'); // 버튼 복구
                }
            },
            error: function(xhr, status, error) {
                // 통신 오류 발생 시
                alert('요청 중 오류가 발생했습니다: ' + error);
                $button.prop('disabled', false).text('TRASH'); // 버튼 복구
            }
        });
    });
});
