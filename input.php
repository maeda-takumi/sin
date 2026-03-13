<div id="memberModal" class="modal hidden" aria-hidden="true">
    <div class="modal-backdrop" data-close-modal="1"></div>
    <div class="modal-content">
        <h2 id="modalTitle">会員追加</h2>
        <form id="memberForm">
            <input type="hidden" name="action" id="formAction" value="create_member">
            <input type="hidden" name="mode" id="formMode" value="create">
            <input type="hidden" name="member_id" id="memberId" value="">

            <div class="form-group">
                <label for="email">メールアドレス</label>
                <input type="email" name="email" id="email" required>
            </div>

            <div class="form-group">
                <label for="user_name">ユーザー名</label>
                <input type="text" name="user_name" id="user_name" required>
            </div>

            <div class="form-group">
                <label for="password">パスワード</label>
                <div class="password-inline">
                    <input type="text" name="password" id="password" required>
                    <button type="button" id="generatePasswordBtn">自動生成</button>
                </div>
            </div>

            <div class="form-group">
                <label for="first_name">姓</label>
                <input type="text" name="first_name" id="first_name">
            </div>

            <div class="form-group">
                <label for="last_name">名</label>
                <input type="text" name="last_name" id="last_name">
            </div>

            <div class="form-group">
                <label for="membership_level">会員レベル</label>
                <select name="membership_level" id="membership_level" required>
                    <option value="2">購読者</option>
                    <option value="4">無効</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="submit" id="saveBtn">保存</button>
                <button type="button" class="secondary" data-close-modal="1">閉じる</button>
            </div>
        </form>
    </div>
</div>
