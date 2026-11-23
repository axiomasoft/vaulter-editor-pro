import type { DocxProfileId, DocxProjectionMode } from './types'

export interface ProfileCapabilities {
  profile: DocxProfileId
  discussionsEnabled: boolean
  threadedComments: boolean
  revisionsEnabled: boolean
  requireFormattingRevisions: boolean
  blockOnUnsupportedMandatory: boolean
  defaultProjection: DocxProjectionMode
  label: string
  description: string
}

export interface ProfileFeatureOptions {
  enableDiscussions?: boolean
  enableRevisions?: boolean
  requireFormattingRevisions?: boolean
}

export const DOCX_PROFILE_IDS: readonly DocxProfileId[] = [
  'standard',
  'discussions',
  'legacy-discussions',
  'revisions',
  'strict-ooxml',
]

export function getProfileCapabilities(
  profile: DocxProfileId,
  options: ProfileFeatureOptions = {},
): ProfileCapabilities {
  const discussionsAllowed = options.enableDiscussions ?? true
  const revisionsAllowed = options.enableRevisions ?? (profile === 'revisions' || profile === 'strict-ooxml')

  switch (profile) {
    case 'standard':
      return {
        profile,
        discussionsEnabled: false,
        threadedComments: false,
        revisionsEnabled: false,
        requireFormattingRevisions: false,
        blockOnUnsupportedMandatory: true,
        defaultProjection: 'original',
        label: 'Standard (Body, Tables & Sections)',
        description: 'Clean document body, tables (D1), and multi-section headers/footers (D2) with accepted text.',
      }
    case 'discussions':
      return {
        profile,
        discussionsEnabled: discussionsAllowed,
        threadedComments: discussionsAllowed,
        revisionsEnabled: false,
        requireFormattingRevisions: false,
        blockOnUnsupportedMandatory: true,
        defaultProjection: 'original',
        label: 'Discussions (Threaded Comments)',
        description: 'Includes range comments, replies, resolve status, and detached discussions (D3).',
      }
    case 'legacy-discussions':
      return {
        profile,
        discussionsEnabled: discussionsAllowed,
        threadedComments: false,
        revisionsEnabled: false,
        requireFormattingRevisions: false,
        blockOnUnsupportedMandatory: true,
        defaultProjection: 'original',
        label: 'Legacy Discussions (Flat Comments)',
        description: 'Flattens comment replies and omits extended threading parts for legacy editors.',
      }
    case 'revisions':
      return {
        profile,
        discussionsEnabled: discussionsAllowed,
        threadedComments: discussionsAllowed,
        revisionsEnabled: revisionsAllowed,
        requireFormattingRevisions: options.requireFormattingRevisions ?? false,
        blockOnUnsupportedMandatory: true,
        defaultProjection: 'review',
        label: 'Revisions (Tracked Changes)',
        description: 'Preserves tracked insertions, deletions, replacement group metadata, and formatting loss reports (D4).',
      }
    case 'strict-ooxml':
      return {
        profile,
        discussionsEnabled: discussionsAllowed,
        threadedComments: discussionsAllowed,
        revisionsEnabled: revisionsAllowed,
        requireFormattingRevisions: options.requireFormattingRevisions ?? true,
        blockOnUnsupportedMandatory: true,
        defaultProjection: 'review',
        label: 'Strict OOXML Gate',
        description: 'Blocks export or import when any mandatory feature or native formatting revision loses fidelity.',
      }
  }
}
