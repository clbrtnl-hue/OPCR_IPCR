import React from "react";
import { Empty, List, Space, Tag, Typography } from "antd";
import dayjs from "dayjs";
import { usePerson } from "~/hooks/usePerson";
import RichTextView from "~/components/RichTextView";
import { ROLE_LABELS } from "~/utils/constants";
import UserAvatar from "~/components/UserAvatar";

export const STAGE_COLORS = {
    head: "blue",
    vp: "geekblue",
    qa: "purple",
    president: "gold",
    employee: "default",
};

export default function CommentList({ comments = [], compact = false, emptyText = "No remarks yet." }) {
    const { openPerson } = usePerson();

    if (comments.length === 0) {
        return <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={emptyText} />;
    }

    return (
        <List
            size="small"
            dataSource={comments}
            renderItem={(comment) => (
                <List.Item>
                    <List.Item.Meta
                        avatar={
                            <UserAvatar
                                name={comment.author_name}
                                role={comment.author_role}
                                size={compact ? "small" : "default"}
                            />
                        }
                        title={
                            <Space size={6} wrap>
                                <span>{comment.author_name}</span>
                                <Tag color={STAGE_COLORS[comment.stage]}>
                                    {ROLE_LABELS[comment.author_role] ?? comment.stage}
                                </Tag>
                                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                    {dayjs(comment.created_at).format("MMM D, YYYY h:mm A")}
                                </Typography.Text>
                            </Space>
                        }
                        description={<RichTextView html={comment.body} onPerson={openPerson} />}
                    />
                </List.Item>
            )}
        />
    );
}
